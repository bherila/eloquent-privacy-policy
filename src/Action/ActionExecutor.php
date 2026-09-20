<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\ActionDenied;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingContext;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Policy\ActionRule;
use BWH\EloquentPrivacyPolicy\Policy\PredicateRule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Policy\Stage;
use BWH\EloquentPrivacyPolicy\Policy\StageReducer;
use BWH\EloquentPrivacyPolicy\Predicate\AllOf;
use BWH\EloquentPrivacyPolicy\Predicate\AnyOf;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Negation;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Runtime\ExistsFacts;
use BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The single write path. The sequence of contract 6 is fixed here and an action
 * cannot reorder it: transaction, anchor locks, authoritative pre-state, facts,
 * authorise, validate, persist, commit, after-commit.
 *
 * Nothing the caller holds is trusted. The target is re-loaded by key with a
 * locking read as a fresh, unprotected instance, so an instance mutated in
 * memory — or one fetched under some other context — contributes nothing but
 * its key. Every read the executor makes inside the transaction is a locking
 * read: a consistent-snapshot read may be older than the anchor lock.
 */
final readonly class ActionExecutor
{
    public function __construct(
        private ?DenialAuditor $auditor = null,
        private StageReducer $reducer = new StageReducer(),
        private PredicateEvaluator $evaluator = new PredicateEvaluator(),
    ) {
    }

    /**
     * @template TModel of Model
     *
     * @param Action<TModel> $action
     * @return ActionResult<TModel>
     *
     * @throws ActionDenied when the action's rule set reduces to Deny
     */
    public function execute(Action $action, ?PrivacyContext $context): ActionResult
    {
        if ($context === null) {
            throw new MissingContext(sprintf(
                'Action "%s" needs a context. Use an explicit anonymous context for anonymous access.',
                $action->name(),
            ));
        }

        $model = $action->model();
        // A missing action policy is an error: a read policy never authorises a write.
        $rules = Privacy::policyFor($model)->actionRules($action->name());

        $prototype = new $model();
        $connection = $prototype->getConnection();

        /** @var (Closure(): void)|null $deferred */
        $deferred = null;

        try {
            // transaction() opens a savepoint when a transaction is already
            // open, so a denial rolls back to the savepoint and the caller's
            // outer transaction stays usable.
            $receipt = $connection->transaction(function () use ($action, $context, $rules, $model, $prototype, $connection, &$deferred): Receipt {
                // Before any lock: an anonymous caller of a private action must
                // not be able to make the executor take and hold row locks.
                if ($context->viewer->isAnonymous() && ! $rules->allowsAnonymous) {
                    throw new ActionDenied(sprintf('Action "%s" on %s was denied.', $action->name(), $model));
                }

                AnchorLock::take($connection, $action->anchors());

                $target = $this->loadTarget($prototype, $action);
                $preState = $target === null ? null : RowSnapshot::fromPersisted($target);
                [$parent, $proposedParent] = $this->loadParents($action, $preState);

                $input = new ActionInput(
                    $action->name(),
                    $preState,
                    $parent === null ? null : RowSnapshot::fromPersisted($parent),
                    $proposedParent === null ? null : RowSnapshot::fromPersisted($proposedParent),
                    $action->changes(),
                );

                $resolved = $this->withActionFacts($action, $context, $input, $connection);

                if ($this->authorise($rules, $resolved, $input) !== Decision::Allow) {
                    throw new ActionDenied(sprintf('Action "%s" on %s was denied.', $action->name(), $model));
                }

                $action->validate($input);

                $persisted = $action->persist($target ?? new $model(), $connection);

                $receipt = new Receipt($action->name(), $model, $this->keyOf($persisted), $action->version($persisted));

                $callback = $action->afterCommit();

                if ($callback !== null) {
                    $run = static function () use ($callback, $receipt): void {
                        $callback($receipt);
                    };

                    if (! $this->scheduleAfterCommit($connection, $run)) {
                        // Without a transactions manager nothing can defer the
                        // callback to an outer commit, and running it after our
                        // savepoint would be a side effect of work that may yet
                        // be rolled back.
                        if ($connection->transactionLevel() > 1) {
                            throw new UnsupportedProtectedOperation(
                                'An after-commit callback inside an outer transaction needs the framework\'s transactions manager.',
                            );
                        }

                        $deferred = $run;
                    }
                }

                return $receipt;
            });
        } catch (ActionDenied $denied) {
            // After our rollback, identifiers only. Outside a transaction only
            // when this is the outermost one: nested, the caller's is still open.
            $this->auditor?->denied(DenialRecord::of($action, $context));

            throw $denied;
        }

        $deferred?->__invoke();

        return new ActionResult($receipt, $model, $context);
    }

    /**
     * The authoritative pre-state: re-read by key under FOR UPDATE, with the
     * model's own global scopes, so a row the model considers gone (soft
     * deleted) is gone here too.
     *
     * @template TModel of Model
     *
     * @param TModel $prototype
     * @param Action<TModel> $action
     * @return TModel|null
     */
    private function loadTarget(Model $prototype, Action $action): ?Model
    {
        $key = $action->targetKey();

        if ($key === null) {
            return null;
        }

        $target = $prototype->newQuery()
            ->where($prototype->getQualifiedKeyName(), '=', $key)
            ->lockForUpdate()
            ->first();

        return $target ?? throw MissingLockedRow::row('target', $prototype->getTable(), $prototype->getKeyName(), $key);
    }

    /**
     * The current (or, for a creation, the creation) parent, and — only when the
     * proposed changes touch the foreign key — the parent the action would move
     * the target to.
     *
     * @template TModel of Model
     *
     * @param Action<TModel> $action
     * @return array{Model|null, Model|null}
     */
    private function loadParents(Action $action, ?RowSnapshot $preState): array
    {
        $link = $action->parent();

        if ($link === null) {
            return [null, null];
        }

        $changes = $action->changes();
        $reparents = array_key_exists($link->foreignKey, $changes);

        if ($preState === null) {
            return [$this->loadParent($link, $reparents ? $changes[$link->foreignKey] : null, 'creation parent'), null];
        }

        $currentKey = $preState->get($link->foreignKey);

        if (! $reparents) {
            return [$this->loadParent($link, $currentKey, 'parent'), null];
        }

        $proposedKey = $changes[$link->foreignKey];

        // Both parents are rows of one table, locked FOR UPDATE. Take them in
        // key order, like anchors: a move from A to B racing a move from B to A
        // would otherwise lock A-then-B against B-then-A and deadlock.
        if ((is_int($currentKey) || is_string($currentKey))
            && (is_int($proposedKey) || is_string($proposedKey))
            && ($proposedKey <=> $currentKey) < 0) {
            $proposed = $this->loadParent($link, $proposedKey, 'proposed parent');

            return [$this->loadParent($link, $currentKey, 'parent'), $proposed];
        }

        return [
            $this->loadParent($link, $currentKey, 'parent'),
            $this->loadParent($link, $proposedKey, 'proposed parent'),
        ];
    }

    /** @param ParentLink<Model> $link */
    private function loadParent(ParentLink $link, mixed $key, string $role): ?Model
    {
        if ($key === null) {
            return null;
        }

        if (! is_int($key) && ! is_string($key)) {
            throw new UnsupportedProtectedOperation(sprintf(
                'The %s is named by "%s", whose value is a %s. A parent key must be an int or a string.',
                $role,
                $link->foreignKey,
                get_debug_type($key),
            ));
        }

        $prototype = new ($link->parent)();
        $column = $link->ownerKey ?? $prototype->getKeyName();

        $parent = $prototype->newQuery()
            ->where($prototype->getTable().'.'.$column, '=', $key)
            ->lockForUpdate()
            ->first();

        return $parent ?? throw MissingLockedRow::row($role, $prototype->getTable(), $column, $key);
    }

    /**
     * @template TModel of Model
     *
     * @param Action<TModel> $action
     */
    private function withActionFacts(
        Action $action,
        PrivacyContext $context,
        ActionInput $input,
        ConnectionInterface $connection,
    ): PrivacyContext {
        $provider = $action->facts();

        if ($provider === null) {
            return $context;
        }

        return $context->withFacts($provider->facts($context, $input, new LockingReads($connection))->all());
    }

    private function authorise(RuleSet $rules, PrivacyContext $context, ActionInput $input): Decision
    {
        if ($context->viewer->isAnonymous() && ! $rules->allowsAnonymous) {
            return Decision::Deny;
        }

        return $this->reducer->reduce(
            $rules,
            fn (PredicateRule|ActionRule $rule, Stage $stage): Decision => $rule instanceof ActionRule
                ? $rule->decide($context, $input)
                : $stage->outcomeFor($this->evaluator->evaluate(
                    $this->actionPredicate($rule, $context, $input),
                    $input->preState ?? throw $this->noPreState($rule),
                    new ExistsFacts(),
                )),
        );
    }

    /**
     * A predicate rule reused in an action rule set is evaluated against the
     * persisted pre-state, and only against it: relationship facts inside an
     * action must come from locking reads through the fact provider, never from
     * the read path's batch loader, which does not lock.
     */
    private function actionPredicate(PredicateRule $rule, PrivacyContext $context, ActionInput $input): Predicate
    {
        if ($input->preState === null) {
            throw $this->noPreState($rule);
        }

        $predicate = $rule->predicate($context);

        if (self::readsARelation($predicate)) {
            throw new UnsupportedProtectedOperation(sprintf(
                'Rule "%s" reads a relationship (Exists/ViaParent), which an action cannot evaluate: '
                .'action facts must be read under lock by an ActionFactProvider.',
                $rule->id(),
            ));
        }

        return $predicate;
    }

    private function noPreState(PredicateRule $rule): UnsupportedProtectedOperation
    {
        return new UnsupportedProtectedOperation(sprintf(
            'Rule "%s" is a predicate rule, and a creation has no persisted pre-state to evaluate it against. '
            .'Authorise a creation with action rules over the creation parent.',
            $rule->id(),
        ));
    }

    private static function readsARelation(Predicate $predicate): bool
    {
        if ($predicate instanceof Exists || $predicate instanceof ViaParent) {
            return true;
        }

        if ($predicate instanceof Negation) {
            return self::readsARelation($predicate->inner);
        }

        if ($predicate instanceof AllOf || $predicate instanceof AnyOf) {
            foreach ($predicate->predicates as $child) {
                if (self::readsARelation($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Hand the callback to the framework so that it runs at the outermost
     * commit. False when this connection has no transactions manager (a bare
     * Capsule, say); the caller then runs it once execute() is past its commit.
     *
     * @param Closure(): void $callback
     */
    private function scheduleAfterCommit(ConnectionInterface $connection, Closure $callback): bool
    {
        if (! $connection instanceof Connection) {
            return false;
        }

        try {
            $connection->afterCommit($callback);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    private function keyOf(Model $persisted): int|string|null
    {
        $key = $persisted->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}

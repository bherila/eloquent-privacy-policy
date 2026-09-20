<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Exceptions\PolicyCycle;
use BWH\EloquentPrivacyPolicy\Exceptions\UncompilablePolicy;
use BWH\EloquentPrivacyPolicy\Predicate\AllOf;
use BWH\EloquentPrivacyPolicy\Predicate\AnyOf;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Negation;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use BWH\EloquentPrivacyPolicy\Privacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Turns (read rule set, context) into a ResolvedReadPolicy. Rule closures run
 * here, once per operation, stage by stage; this is where context errors
 * surface. A stage that folds to a decisive constant stops resolution, exactly
 * as a decisive stage stops evaluation.
 */
final readonly class PolicyResolver
{
    public function __construct(private GroupExecutor $executor = new SynchronousGroupExecutor())
    {
    }

    /** @param class-string<Model> $model */
    public function resolveRead(string $model, PrivacyContext $context): ResolvedReadPolicy
    {
        return $this->resolve($model, $context, [$model]);
    }

    /**
     * @param class-string<Model> $model
     * @param list<class-string<Model>> $stack models being expanded, outermost first
     */
    private function resolve(string $model, PrivacyContext $context, array $stack): ResolvedReadPolicy
    {
        $rules = Privacy::policyFor($model)->readRules();

        if ($context->viewer->isAnonymous() && ! $rules->allowsAnonymous) {
            return ResolvedReadPolicy::denyAll();
        }

        $stages = [];

        foreach (Stage::cases() as $stage) {
            $stages[$stage->value] = $this->executor->run(
                $stage,
                $rules->rulesOf($stage),
                function (PredicateRule|ActionRule $rule) use ($context, $stack, $model): Predicate {
                    if (! $rule instanceof PredicateRule) {
                        throw new UncompilablePolicy(sprintf('Rule "%s" of %s is runtime-only.', $rule->id(), $model));
                    }

                    return $this->expand($rule->predicate($context), $context, $stack);
                },
            );

            if ($this->isDecisive($stage, $stages[$stage->value])) {
                break;
            }
        }

        return new ResolvedReadPolicy($stages, $rules->terminal);
    }

    /** @param array<string, Predicate> $predicates */
    private function isDecisive(Stage $stage, array $predicates): bool
    {
        foreach ($predicates as $predicate) {
            // Mandatory is decisive when a boundary can never hold; the others when a rule always fires.
            if ($stage === Stage::Mandatory ? $predicate->isNever() : $predicate->isAlways()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<class-string<Model>> $stack */
    private function expand(Predicate $predicate, PrivacyContext $context, array $stack): Predicate
    {
        return match (true) {
            $predicate instanceof ViaParent => $this->expandParent($predicate, $context, $stack),
            $predicate instanceof AllOf => Predicate::all(
                ...array_map(fn (Predicate $child): Predicate => $this->expand($child, $context, $stack), $predicate->predicates),
            ),
            $predicate instanceof AnyOf => Predicate::any(
                ...array_map(fn (Predicate $child): Predicate => $this->expand($child, $context, $stack), $predicate->predicates),
            ),
            $predicate instanceof Negation => Predicate::not($this->expand($predicate->inner, $context, $stack)),
            $predicate instanceof Exists => Exists::build(
                $predicate->table,
                $predicate->matches,
                $this->expand($predicate->where, $context, $stack),
            ),
            default => $predicate,
        };
    }

    /** @param list<class-string<Model>> $stack */
    private function expandParent(ViaParent $via, PrivacyContext $context, array $stack): Predicate
    {
        if (in_array($via->parent, $stack, true)) {
            throw new PolicyCycle(sprintf('Policy cycle: %s -> %s', implode(' -> ', $stack), $via->parent));
        }

        $parent = new ($via->parent)();

        // The EXISTS is built from the parent's privacy policy and its
        // soft-delete column only. Any other global scope (a tenant boundary,
        // say) would be silently absent from it, leaving a child visible whose
        // parent is hidden on a direct protected query. Refuse rather than
        // weaken: the boundary has to be stated in the parent's policy, where
        // ViaParent does expand it.
        foreach (array_keys($parent->getGlobalScopes()) as $scope) {
            if ($scope !== SoftDeletingScope::class) {
                throw new UncompilablePolicy(sprintf(
                    'ViaParent to %s cannot honour its global scope "%s". State that boundary in the parent\'s mandatory stage instead.',
                    $via->parent,
                    $scope,
                ));
            }
        }

        $visible = $this->resolve($via->parent, $context, [...$stack, $via->parent])->toPredicate();

        if (in_array(SoftDeletes::class, class_uses_recursive($parent), true) && method_exists($parent, 'getDeletedAtColumn')) {
            $visible = Predicate::all($visible, Col::datetime($parent->getDeletedAtColumn())->isNull());
        }

        return Exists::in($parent->getTable())
            ->matchCols($via->foreignKey, Col::int($via->ownerKey ?? $parent->getKeyName()))
            ->where($visible);
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One write the application wants to make, described so that
 * {@see ActionExecutor} can authorise it in a sequence the action cannot
 * reorder. An action declares what it intends; it never decides whether it may.
 *
 * Implementations are usually small value objects; {@see BaseAction} supplies
 * the neutral defaults so only the interesting parts are written out.
 *
 * @template TModel of Model
 */
interface Action
{
    /** The name of the action policy to authorise against, e.g. "record.update". */
    public function name(): string;

    /** @return class-string<TModel> */
    public function model(): string;

    /** The key of the row being changed, or null for a creation. */
    public function targetKey(): int|string|null;

    /**
     * The proposed attribute changes, not applied to anything yet. Rules see
     * them separately from the persisted pre-state.
     *
     * @return array<string, mixed>
     */
    public function changes(): array;

    /**
     * The rows this action's authorisation depends on, locked FOR UPDATE before
     * any grant is read. See contract 6.1: an action with no anchors is outside
     * the revocation protocol.
     *
     * @return list<Anchor>
     */
    public function anchors(): array;

    /** @return ParentLink<Model>|null */
    public function parent(): ?ParentLink;

    /** The facts this action's rules read, or null when they read none. */
    public function facts(): ?ActionFactProvider;

    /**
     * Domain validation, after authorisation and inside the transaction. Throws
     * to reject; the throw rolls the action back like any other error.
     */
    public function validate(ActionInput $input): void;

    /**
     * Apply the change. The instance is the one the executor loaded and locked
     * (a new instance for a creation), never one the caller was holding, and it
     * carries no privacy context, so an ordinary save() works on it.
     *
     * @param TModel $target
     * @return TModel the persisted model
     */
    public function persist(Model $target, ConnectionInterface $connection): Model;

    /**
     * Optimistic-lock version or revision to put on the receipt, if the model
     * has one.
     *
     * @param TModel $persisted
     */
    public function version(Model $persisted): int|string|null;

    /**
     * Work to do once the outermost transaction has committed — never inside
     * it, and never at all if the action was denied.
     *
     * @return (Closure(Receipt): void)|null
     */
    public function afterCommit(): ?Closure;
}

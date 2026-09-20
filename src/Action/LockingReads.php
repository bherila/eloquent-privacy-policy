<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Predicate\Identifier;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * The only database handle an {@see ActionFactProvider} is given. Every builder
 * it hands out already carries a shared lock, because a plain read here would
 * quietly void the revocation protocol.
 *
 * Under REPEATABLE READ a plain read is answered from the transaction's
 * consistent snapshot, which may have been taken *before* the anchor lock was
 * granted: the action would then authorise itself against a version of the
 * grant that a revoker has already replaced and committed. That is most acute
 * on the savepoint path, where an outer transaction has been reading for some
 * time and its snapshot is older still — the savepoint takes no new one. A
 * locking read is answered from the current committed version, and blocks
 * rather than lying.
 *
 * It is a wrapper, not a suggestion: there is no accessor for the underlying
 * connection, so "I forgot the lock" is not a reachable state.
 */
final readonly class LockingReads
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** A query over $table whose rows are read with a shared lock held to the end of the transaction. */
    public function table(string $table): Builder
    {
        return $this->connection->table(Identifier::assert($table))->sharedLock();
    }
}

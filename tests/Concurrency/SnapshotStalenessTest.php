<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Action\LockingReads;
use Illuminate\Support\Facades\DB;

/**
 * Why contract 6.1 says locking reads are required rather than preferable.
 *
 * Under REPEATABLE READ a transaction answers plain reads from the snapshot
 * its first consistent read established. A grant revoked and committed after
 * that moment is invisible to a plain read for the rest of the transaction —
 * and a savepoint does not start a new snapshot, so an action running inside
 * a caller's long transaction inherits however old that one is.
 */
final class SnapshotStalenessTest extends ConcurrencyTestCase
{
    public function test_a_plain_read_can_be_stale_where_a_locking_read_is_current(): void
    {
        $connection = DB::connection();
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        $connection->beginTransaction();

        try {
            // The caller's transaction reads something, and is now pinned to
            // that moment for every plain read it makes afterwards.
            $this->assertNotNull($this->plainRead(), 'the grant is there to begin with');

            // Another connection revokes the grant and commits.
            $this->independentConnection()->exec(sprintf(
                'DELETE FROM %s WHERE user_id = %d',
                Fixture::GRANTS,
                Fixture::VIEWER,
            ));

            $this->assertNotNull($this->plainRead(), 'a plain read still sees the revoked grant');
            $this->assertNull($this->lockingRead(), 'the locking read sees the revocation');

            // The savepoint an action opens inside that transaction inherits
            // the same snapshot, which is where this matters most.
            $connection->transaction(function () use ($connection): void {
                $this->assertGreaterThan(1, $connection->transactionLevel(), 'this should be a savepoint');
                $this->assertNotNull($this->plainRead(), 'the savepoint inherits the stale snapshot');
                $this->assertNull($this->lockingRead(), 'and the locking read is still current inside it');
            });
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(0, $this->grants(), 'the revocation was committed all along');
    }

    private function plainRead(): ?object
    {
        return DB::connection()->table(Fixture::GRANTS)->where('user_id', '=', Fixture::VIEWER)->first(['id']);
    }

    /** The same read, through the only handle an action's fact provider is given. */
    private function lockingRead(): ?object
    {
        return (new LockingReads(DB::connection()))->table(Fixture::GRANTS)
            ->where('user_id', '=', Fixture::VIEWER)
            ->first(['id']);
    }
}

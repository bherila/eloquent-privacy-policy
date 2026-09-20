<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The barrier the processes coordinate on, and the log the test reads
 * afterwards. A step is reached by inserting its name; waiting for a step is
 * polling for that row until it appears or the wait times out.
 *
 * Nothing here sleeps for a fixed time hoping the other side got there first:
 * a process blocks until the step it needs has actually happened, and a test
 * that would otherwise hang fails with the step it was waiting for.
 *
 * The connection must be outside the work transaction — both because an event
 * written inside it would vanish on a rollback, and because a transaction's
 * REPEATABLE READ snapshot would never see the other process's rows.
 */
final class Signals
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly float $timeout = 20.0,
    ) {
    }

    /** Mark a step as reached. Returns the moment it was recorded. */
    public function emit(string $name): float
    {
        $at = microtime(true);

        $this->connection->table(Fixture::EVENTS)->insert(['name' => $name, 'at' => $at]);

        return $at;
    }

    /** Block until another process reaches $name. Returns how long that took. */
    public function await(string $name, ?float $timeout = null): float
    {
        $started = microtime(true);
        $deadline = $started + ($timeout ?? $this->timeout);

        while (! $this->has($name)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException(sprintf('Timed out after %.1fs waiting for "%s".', $timeout ?? $this->timeout, $name));
            }

            usleep(2_000);
        }

        return microtime(true) - $started;
    }

    public function has(string $name): bool
    {
        return $this->connection->table(Fixture::EVENTS)->where('name', '=', $name)->count() > 0;
    }

    /** @return list<string> every step reached, in the order the database sequenced the inserts */
    public function names(): array
    {
        $names = [];

        foreach ($this->connection->table(Fixture::EVENTS)->orderBy('id')->get() as $row) {
            $name = ((array) $row)['name'] ?? null;

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}

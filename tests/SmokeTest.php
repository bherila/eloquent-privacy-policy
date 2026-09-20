<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests;

use Illuminate\Support\Facades\DB;

/**
 * Verifies the database contract in tests/TestCase.php actually holds for
 * whichever connection CI (or a local run) selected via DB_CONNECTION,
 * before any package-specific test relies on it.
 */
final class SmokeTest extends TestCase
{
    public function test_connection_is_usable(): void
    {
        $this->assertSame(1, (int) DB::selectOne('select 1 as ok')->ok);
    }

    public function test_foreign_keys_are_enforced_on_sqlite(): void
    {
        if ($this->usingRealEngine()) {
            $this->markTestSkipped('PRAGMA foreign_keys is sqlite-specific.');
        }

        $result = DB::selectOne('PRAGMA foreign_keys');

        $this->assertSame(1, (int) $result->foreign_keys);
    }

    public function test_records_the_engine_version(): void
    {
        $version = $this->usingRealEngine()
            ? (string) DB::selectOne('select version() as version')->version
            : (string) DB::selectOne('select sqlite_version() as version')->version;

        $this->addToAssertionCount(1);

        fwrite(STDERR, sprintf(
            "[SmokeTest] connection=%s version=%s\n",
            $this->dbConnection(),
            $version
        ));
    }
}

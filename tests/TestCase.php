<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base test case for the package's test suite.
 *
 * The database connection under test is controlled entirely through
 * environment variables, so the same test suite runs unmodified against
 * sqlite (the default, in-process) and against real MySQL / MariaDB servers
 * in CI. Consuming CI configurations and other authors rely on this exact
 * contract — do not change the variable names or the defaulting behavior
 * without updating every caller.
 *
 * Recognised environment variables:
 *
 *   DB_CONNECTION  sqlite | mysql | mariadb (default: sqlite)
 *   DB_HOST        default: 127.0.0.1
 *   DB_PORT        default: 3306
 *   DB_DATABASE    default: :memory: for sqlite, otherwise "testing"
 *   DB_USERNAME    default: root
 *   DB_PASSWORD    default: "" (empty string)
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $connection = $this->dbConnection();

        $config = match ($connection) {
            'mysql', 'mariadb' => [
                'driver' => $connection,
                'host' => self::dbEnv('DB_HOST', '127.0.0.1'),
                'port' => self::dbEnv('DB_PORT', '3306'),
                'database' => self::dbEnv('DB_DATABASE', 'testing'),
                'username' => self::dbEnv('DB_USERNAME', 'root'),
                'password' => self::dbEnv('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };

        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}", $config);
    }

    /**
     * The database connection under test, as named by DB_CONNECTION.
     *
     * Defaults to sqlite when unset, since sqlite is the connection every
     * environment can run without additional setup.
     */
    protected function dbConnection(): string
    {
        $connection = self::dbEnv('DB_CONNECTION', 'sqlite');

        return match ($connection) {
            'mysql', 'mariadb', 'sqlite' => $connection,
            default => 'sqlite',
        };
    }

    /**
     * Reads a DB_* environment variable directly via getenv()/$_ENV, bypassing
     * Laravel's env() helper (which Larastan restricts to the config
     * directory). CI sets these as real process environment variables, and
     * phpunit.xml's <env> entries populate both $_ENV and putenv(), so both
     * are checked here.
     */
    private static function dbEnv(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Whether the current run targets a real database engine (MySQL or
     * MariaDB) rather than the in-memory sqlite connection.
     */
    protected function usingRealEngine(): bool
    {
        return in_array($this->dbConnection(), ['mysql', 'mariadb'], true);
    }

    /**
     * Skip the current test when it depends on behavior sqlite cannot
     * exercise (for example real row locking or concurrent transactions).
     */
    protected function requiresRealEngine(): void
    {
        if (! $this->usingRealEngine()) {
            $this->markTestSkipped(
                'This test requires a real database engine (mysql or mariadb); '
                .'set DB_CONNECTION accordingly to run it.'
            );
        }
    }
}

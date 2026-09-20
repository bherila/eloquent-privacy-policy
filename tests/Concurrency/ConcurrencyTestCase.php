<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Harness for the races: real tables, real child processes, each with its own
 * connection, and enough reporting that a failure says what happened instead
 * of timing out silently.
 *
 * These tests need row locks and more than one connection, so they run on a
 * real engine only.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    protected const float CHILD_TIMEOUT = 60.0;

    protected Signals $signals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresRealEngine();

        Fixture::createTables(DB::connection()->getSchemaBuilder());
        Fixture::seed(DB::connection());
        Fixture::registerPolicies();

        $this->signals = new Signals(DB::connection());
    }

    protected function tearDown(): void
    {
        if ($this->usingRealEngine()) {
            Fixture::dropTables(DB::connection()->getSchemaBuilder());
        }

        parent::tearDown();
    }

    /**
     * Start one child process. It inherits the database settings and gets the
     * step names it should wait for and announce.
     *
     * @param array<string, string|int> $parameters
     */
    protected function start(string $script, array $parameters = []): Process
    {
        $php = (new PhpExecutableFinder())->find();

        $process = new Process(
            [$php === false ? PHP_BINARY : $php, __DIR__.'/'.$script],
            dirname(__DIR__, 2),
            [...$this->databaseEnvironment(), ...array_map(static fn (string|int $v): string => (string) $v, $parameters)],
            null,
            self::CHILD_TIMEOUT,
        );

        $process->start();

        return $process;
    }

    /**
     * Wait for a child and return what it reported. A child that failed takes
     * the test down with its stderr attached.
     *
     * @return array<string, mixed>
     */
    protected function finish(Process $process, string $what): array
    {
        $process->wait();

        $this->assertSame(0, $process->getExitCode(), sprintf(
            "The %s process failed.\n--- stdout ---\n%s\n--- stderr ---\n%s",
            $what,
            $process->getOutput(),
            $process->getErrorOutput(),
        ));

        $reported = json_decode(trim($process->getOutput()), true);

        $this->assertIsArray($reported, sprintf('The %s process reported nothing usable: %s', $what, $process->getOutput()));

        /** @var array<string, mixed> $reported */
        return $reported;
    }

    /** A second, independent connection to the same database. */
    protected function independentConnection(): PDO
    {
        $environment = $this->databaseEnvironment();

        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $environment['DB_HOST'], $environment['DB_PORT'], $environment['DB_DATABASE']),
            $environment['DB_USERNAME'],
            $environment['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    protected function title(): string
    {
        $row = DB::table(Fixture::ITEMS)->where('id', '=', Fixture::ITEM)->first();
        $title = $row === null ? null : ((array) $row)['title'] ?? null;

        return is_string($title) ? $title : '';
    }

    protected function grants(): int
    {
        return DB::table(Fixture::GRANTS)->where('user_id', '=', Fixture::VIEWER)->count();
    }

    /**
     * Asserts that $first was recorded before $second, printing the whole log
     * when it was not.
     */
    protected function assertHappenedBefore(string $first, string $second): void
    {
        $names = $this->signals->names();
        $order = array_flip($names);

        $this->assertArrayHasKey($first, $order, sprintf('"%s" never happened; the log is %s', $first, implode(' -> ', $names)));
        $this->assertArrayHasKey($second, $order, sprintf('"%s" never happened; the log is %s', $second, implode(' -> ', $names)));

        $this->assertLessThan(
            $order[$second],
            $order[$first],
            sprintf('expected "%s" before "%s"; the log is %s', $first, $second, implode(' -> ', $names)),
        );
    }

    /**
     * The settings the test process is actually connected with, so a child
     * connects to the same database rather than to whatever the environment
     * happens to say.
     *
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        return [
            'DB_CONNECTION' => $this->dbConnection(),
            'DB_HOST' => $this->setting('host'),
            'DB_PORT' => $this->setting('port'),
            'DB_DATABASE' => $this->setting('database'),
            'DB_USERNAME' => $this->setting('username'),
            'DB_PASSWORD' => $this->setting('password'),
        ];
    }

    private function setting(string $option): string
    {
        $value = DB::connection()->getConfig($option);

        return is_scalar($value) ? (string) $value : '';
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Throwable;

/**
 * What a child process needs before it can race: Eloquent booted on its own
 * connections, the fixtures' policies registered, and a tiny protocol for
 * reporting back.
 *
 * Two connections, deliberately: "work" carries the transaction under test,
 * "signal" stays outside it so the barrier keeps working while work is
 * mid-transaction.
 */
final class ChildRuntime
{
    private function __construct(
        public readonly Manager $capsule,
        public readonly Signals $signals,
    ) {
    }

    public static function boot(): self
    {
        $container = new Container();
        // Without this the framework refuses after-commit callbacks; a bare
        // Capsule has no transactions manager of its own.
        $container->singleton('db.transactions', static fn (): DatabaseTransactionsManager => new DatabaseTransactionsManager());

        $capsule = new Manager($container);

        foreach (['work', 'signal'] as $name) {
            $capsule->addConnection(self::config(), $name);
        }

        $capsule->getDatabaseManager()->setDefaultConnection('work');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Fixture::registerPolicies();

        return new self($capsule, new Signals($capsule->getConnection('signal')));
    }

    public function work(): ConnectionInterface
    {
        return $this->capsule->getConnection('work');
    }

    /** A parameter the parent process passed in the environment. */
    public static function env(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    public static function intEnv(string $name, int $default = 0): int
    {
        $value = self::env($name);

        return $value === null ? $default : (int) $value;
    }

    /**
     * Report back as one line of JSON, and let a failure be a non-zero exit
     * with something readable on stderr rather than a silent hang.
     *
     * @param callable(self): array<string, mixed> $work
     */
    public static function run(callable $work): never
    {
        try {
            $runtime = self::boot();
            $result = $work($runtime);

            echo json_encode(['ok' => true, ...$result], JSON_THROW_ON_ERROR), "\n";

            exit(0);
        } catch (Throwable $failure) {
            fwrite(STDERR, sprintf("%s: %s\n%s\n", $failure::class, $failure->getMessage(), $failure->getTraceAsString()));

            echo json_encode(['ok' => false, 'error' => $failure->getMessage()], JSON_THROW_ON_ERROR), "\n";

            exit(1);
        }
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return [
            'driver' => (string) self::env('DB_CONNECTION', 'mysql'),
            'host' => (string) self::env('DB_HOST', '127.0.0.1'),
            'port' => (string) self::env('DB_PORT', '3306'),
            'database' => (string) self::env('DB_DATABASE', 'testing'),
            'username' => (string) self::env('DB_USERNAME', 'root'),
            'password' => (string) self::env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }
}

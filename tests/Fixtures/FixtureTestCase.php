<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures;

use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\Operation;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Context\ResourceScope;
use BWH\EloquentPrivacyPolicy\Context\Viewer;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\PolicyResolver;
use BWH\EloquentPrivacyPolicy\Policy\StageReducer;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator;
use BWH\EloquentPrivacyPolicy\Runtime\RelationFactLoader;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;
use BWH\EloquentPrivacyPolicy\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use Error;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Base class of the independent suites. Owns nothing static: every test starts
 * from a flushed registry and an empty set of fixture tables.
 */
abstract class FixtureTestCase extends TestCase
{
    use FixtureSchema;

    /** The single clock every fixture context uses. */
    public const string NOW = '2026-06-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Privacy::flush();
        Model::automaticallyEagerLoadRelationships(false);
        Model::preventLazyLoading(false);
        $this->dropFixtureTables();
    }

    protected function tearDown(): void
    {
        Model::automaticallyEagerLoadRelationships(false);
        Model::preventLazyLoading(false);
        $this->dropFixtureTables();
        Privacy::flush();

        parent::tearDown();
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $scope
     */
    protected function context(
        int|string|null $viewer,
        array $facts = [],
        array $scope = [],
        string $operation = 'fixture.list',
    ): PrivacyContext {
        return new PrivacyContext(
            $viewer === null ? Viewer::anonymous() : Viewer::identified($viewer),
            new Operation($operation),
            new ResourceScope($scope),
            facts: new Facts($facts),
            now: $this->now(),
        );
    }

    /**
     * Run $work while counting the queries the connection issues.
     *
     * @template T
     *
     * @param callable(): T $work
     * @return array{T, list<string>}
     */
    protected function countingQueries(callable $work): array
    {
        $queries = [];
        $state = new class
        {
            public bool $recording = true;
        };

        // Laravel has no "unlisten"; retiring the listener through a flag is
        // what stops a second call in the same test seeing the first one's.
        DB::listen(static function (QueryExecuted $query) use (&$queries, $state): void {
            if ($state->recording) {
                $queries[] = $query->sql;
            }
        });

        try {
            $result = $work();
        } finally {
            $state->recording = false;
        }

        return [$result, $queries];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function insert(string $table, array $rows): void
    {
        DB::table($table)->insert($rows);
    }

    /**
     * Call a method with arguments its signature refuses, so a test can prove
     * the runtime rejection of something static analysis already rules out.
     *
     * @param list<mixed> $arguments
     */
    protected function callLoosely(object $target, string $method, array $arguments = []): mixed
    {
        $callable = [$target, $method];

        if (! is_callable($callable)) {
            // Exactly what PHP itself raises, so a caller can catch one thing.
            throw new Error(sprintf('Call to undefined method %s::%s()', $target::class, $method));
        }

        return $callable(...$arguments);
    }

    /**
     * The keys the runtime interpreter admits, over exactly the rows the
     * model's ordinary query returns (so global scopes match the SQL path).
     *
     * @param class-string<Model> $model
     * @return list<int|string>
     */
    protected function runtimeVisibleKeys(string $model, PrivacyContext $context): array
    {
        $prototype = new $model();
        $key = $prototype->getKeyName();

        $resolved = (new PolicyResolver())->resolveRead($model, $context);
        $rows = [];

        foreach ($model::query()->orderBy($key)->get() as $row) {
            $rows[] = RowSnapshot::fromModel($row);
        }

        $facts = (new RelationFactLoader(DB::connection()))->prepare($resolved->toPredicate(), $rows);
        $evaluator = new PredicateEvaluator();
        $reducer = new StageReducer();
        $keys = [];

        foreach ($rows as $row) {
            if ($resolved->decide($row, $facts, $evaluator, $reducer) === Decision::Allow) {
                $raw = $row->get($key);
                $keys[] = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : (string) $raw);
            }
        }

        return $keys;
    }

    /**
     * Both interpreters must admit exactly $expected.
     *
     * @param class-string<Model> $model
     * @param list<int|string> $expected
     */
    protected function assertBothInterpretersSee(string $model, PrivacyContext $context, array $expected): void
    {
        $prototype = new $model();
        $query = Privacy::query($model, $context);

        $this->assertSame($expected, $query->orderBy($prototype->getKeyName())->get()->modelKeys(), 'SQL interpreter');
        $this->assertSame($expected, $this->runtimeVisibleKeys($model, $context), 'runtime interpreter');
    }
}

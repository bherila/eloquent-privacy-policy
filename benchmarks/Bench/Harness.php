<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Benchmarks;

use Illuminate\Database\Connection;

/**
 * Measurement plumbing shared by every timed comparison: statement capture
 * (via Connection::enableQueryLog(), which needs no event dispatcher), wall
 * time (hrtime, median/p95 over many iterations), and EXPLAIN capture on
 * whichever engine is under test.
 */
final class Harness
{
    /**
     * Runs $run() once with the query log enabled and returns exactly the
     * statements it issued: SQL text (with ? placeholders, never
     * interpolated), bindings, and the driver's own reported time.
     *
     * @return array{statements: list<array{sql: string, bindings: list<mixed>, time_ms: float}>, result: mixed}
     */
    public static function capture(Connection $connection, callable $run): array
    {
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $result = $run();

        $log = $connection->getQueryLog();
        $connection->flushQueryLog();
        $connection->disableQueryLog();

        $statements = array_map(
            static fn (array $entry): array => [
                'sql' => $entry['query'],
                'bindings' => $entry['bindings'],
                'time_ms' => (float) $entry['time'],
            ],
            $log,
        );

        return ['statements' => $statements, 'result' => $result];
    }

    /**
     * Wall-clock timing with the query log OFF (so logging overhead never
     * pollutes the numbers we report): $warmups untimed runs, then
     * $iterations timed runs via hrtime(as_number: true) (nanoseconds).
     *
     * @return array{median_ns: float, p95_ns: float, min_ns: float, max_ns: float, samples_ns: list<float>}
     */
    public static function time(callable $run, int $warmups = 5, int $iterations = 30): array
    {
        for ($i = 0; $i < $warmups; $i++) {
            $run();
        }

        $samples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $run();
            $samples[] = (float) (hrtime(true) - $start);
        }

        sort($samples);

        return [
            'median_ns' => self::percentile($samples, 0.50),
            'p95_ns' => self::percentile($samples, 0.95),
            'min_ns' => $samples[0],
            'max_ns' => $samples[count($samples) - 1],
            'samples_ns' => $samples,
        ];
    }

    /**
     * EXPLAIN QUERY PLAN (sqlite) or EXPLAIN (mysql/mariadb) for the exact SQL
     * and bindings a captured statement used. Captured by the script against
     * the live engine, never pasted from memory or reasoned about in the
     * abstract.
     *
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public static function explain(Connection $connection, string $driver, string $sql, array $bindings): array
    {
        $prepared = $connection->prepareBindings($bindings);
        $verb = $driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';

        $pdo = $connection->getPdo();
        $statement = $pdo->prepare($verb.$sql);
        $statement->execute($prepared);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    private static function percentile(array $sortedSamples, float $p): float
    {
        $count = count($sortedSamples);

        if ($count === 1) {
            return $sortedSamples[0];
        }

        $rank = $p * ($count - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return $sortedSamples[$lower];
        }

        $fraction = $rank - $lower;

        return $sortedSamples[$lower] + ($sortedSamples[$upper] - $sortedSamples[$lower]) * $fraction;
    }
}

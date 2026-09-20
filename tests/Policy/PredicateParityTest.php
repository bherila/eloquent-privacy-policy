<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Policy;

use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Exceptions\UncompilablePolicy;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Matrix\MatrixSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support\Interpreters;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support\PredicateDescriber;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support\PredicateTreeGenerator;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support\Rng;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Contract section 4: one representation, two interpreters. For every
 * predicate the compiler and the runtime evaluator must select exactly the
 * same rows, on every engine.
 */
final class PredicateParityTest extends FixtureTestCase
{
    /**
     * How many trees each sweep generates. Every run is reproducible from the
     * seed alone, so a shorter run on a real engine is a strict prefix of the
     * long one rather than a different search.
     *
     * sqlite is in-process and cheap, so it always runs the full sweep; a real
     * engine defaults to half that to keep the CI matrix at a few minutes, and
     * PRIVACY_PARITY_TREES overrides either.
     */
    private const int TREES = 300;

    private const int TREES_ON_A_REAL_ENGINE = 150;

    private const int SEED = 20260919;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMatrixSchema();
        MatrixSeed::seed();
    }

    public function test_random_predicate_trees_agree_between_the_two_interpreters(): void
    {
        $this->assertTreesAgree(new PredicateTreeGenerator(new Rng(self::SEED)), self::SEED);
    }

    /** How many trees this run generates; see the constants above. */
    private function treeCount(): int
    {
        $override = (int) getenv('PRIVACY_PARITY_TREES');

        if ($override > 0) {
            return $override;
        }

        return $this->usingRealEngine() ? self::TREES_ON_A_REAL_ENGINE : self::TREES;
    }

    /**
     * Separated from the main sweep so that a fractional-second failure is
     * never confused with an ordinary one. Section 4.3 names
     * "Y-m-d H:i:s[.u]" as an accepted raw datetime, so both interpreters are
     * expected to agree on a column that stores one.
     */
    public function test_random_predicate_trees_agree_over_fractional_second_datetimes(): void
    {
        $seed = self::SEED + 1;

        $this->assertTreesAgree(new PredicateTreeGenerator(new Rng($seed), fractional: true), $seed);
    }

    private function assertTreesAgree(PredicateTreeGenerator $generator, int $seed): void
    {
        $trees = $this->treeCount();

        for ($i = 0; $i < $trees; $i++) {
            $predicate = $generator->tree(3);

            $sql = Interpreters::sql('fx_matrix', $predicate);
            $runtime = Interpreters::runtime('fx_matrix', $predicate);

            $this->assertSame($sql, $runtime, sprintf(
                "SQL and runtime disagree.\nseed=%d tree#=%d engine=%s\npredicate: %s\nsql only: %s\nruntime only: %s",
                $seed,
                $i,
                $this->dbConnection(),
                PredicateDescriber::describe($predicate),
                implode(',', array_diff($sql, $runtime)),
                implode(',', array_diff($runtime, $sql)),
            ));
        }
    }

    // ----- hand-written cases from section 4.2 / 4.3 -------------------------

    public function test_negated_comparison_is_true_for_a_null_column_in_both_interpreters(): void
    {
        $predicate = Predicate::not(Col::int('c_int')->eq(1));

        $nullRows = $this->idsWhereNull('c_int');

        $this->assertNotSame([], $nullRows, 'the fixture must contain NULL c_int rows');
        $this->assertSame(
            $nullRows,
            array_values(array_intersect(Interpreters::sql('fx_matrix', $predicate), $nullRows)),
            'NOT (col = v) must hold for a NULL column in SQL',
        );
        $this->assertSame(
            Interpreters::sql('fx_matrix', $predicate),
            Interpreters::runtime('fx_matrix', $predicate),
        );
    }

    public function test_in_with_an_empty_list_is_never(): void
    {
        $predicate = Col::int('c_int')->in([]);

        $this->assertTrue($predicate->isNever());
        $this->assertSame([], Interpreters::sql('fx_matrix', $predicate));
        $this->assertSame([], Interpreters::runtime('fx_matrix', $predicate));
        $this->assertSame([], Interpreters::sql('fx_matrix', Col::string('c_str')->in([])));
    }

    public function test_string_equality_is_exact_on_every_engine(): void
    {
        foreach (['abc', 'Abc', 'abc ', '', 'zzz'] as $needle) {
            $predicate = Col::string('c_str')->eq($needle);

            $expected = $this->idsWhereRawEquals('c_str', $needle);
            $sql = Interpreters::sql('fx_matrix', $predicate);

            $this->assertSame($expected, $sql, sprintf(
                'string equality on %s is not exact for %s',
                $this->dbConnection(),
                var_export($needle, true),
            ));
            $this->assertSame($expected, Interpreters::runtime('fx_matrix', $predicate));
        }
    }

    public function test_in_over_strings_is_exact_on_every_engine(): void
    {
        $predicate = Col::string('c_str')->in(['abc', '']);

        $expected = array_merge(
            $this->idsWhereRawEquals('c_str', 'abc'),
            $this->idsWhereRawEquals('c_str', ''),
        );
        sort($expected);

        $this->assertSame($expected, Interpreters::sql('fx_matrix', $predicate));
        $this->assertSame($expected, Interpreters::runtime('fx_matrix', $predicate));
    }

    public function test_eq_col_over_strings_and_ints_agrees_and_is_false_for_nulls(): void
    {
        foreach ([Col::string('c_str')->eqCol(Col::string('c_str2')), Col::int('c_int')->eqCol(Col::int('c_int2'))] as $predicate) {
            $sql = Interpreters::sql('fx_matrix', $predicate);

            $this->assertSame($sql, Interpreters::runtime('fx_matrix', $predicate));
            $this->assertSame([], array_values(array_intersect($sql, $this->idsWhereNull('c_str'))));
        }
    }

    /**
     * Section 4.1: an EXISTS is correlated on integer keys only. A string key
     * cannot be kept exact on every engine — MariaDB caches a correlated
     * subquery by the outer column's own (case-insensitive) collation, so
     * 'Abc' would inherit the answer computed for 'abc' whatever the subquery
     * itself compares — so the node is refused at construction instead.
     */
    public function test_an_exists_may_only_be_correlated_on_integer_keys(): void
    {
        $rejected = [
            'string/string' => [Col::string('c_str'), Col::string('k_str')],
            'bool/bool' => [Col::bool('c_bool'), Col::bool('c_bool')],
            'datetime/datetime' => [Col::datetime('c_dt'), Col::datetime('c_dt')],
            'int/string' => [Col::int('id'), Col::string('k_str')],
            'string/int' => [Col::string('c_str'), Col::int('m_id')],
        ];

        foreach ($rejected as $label => [$outer, $inner]) {
            try {
                Exists::in('fx_matrix_child')->matchCols($outer, $inner)->where(Predicate::always());
                $this->fail(sprintf('an EXISTS correlated on %s keys was accepted', $label));
            } catch (UncompilablePolicy $exception) {
                $this->assertStringContainsString('only integer keys are supported', $exception->getMessage());
            }
        }

        // The int/int forms stay available, spelled either way.
        $this->assertInstanceOf(
            Exists::class,
            Exists::in('fx_matrix_child')->match('id', 'm_id')->where(Predicate::always()),
        );
        $this->assertInstanceOf(
            Exists::class,
            Exists::in('fx_matrix_child')->matchCols(Col::int('id'), Col::int('m_id'))->where(Predicate::always()),
        );

        // A second match() on a non-integer key is refused as well.
        $this->expectException(UncompilablePolicy::class);
        Exists::in('fx_matrix_child')
            ->match('id', 'm_id')
            ->matchCols(Col::string('c_str'), Col::string('k_str'))
            ->where(Predicate::always());
    }

    /**
     * The position a collation surprise would hide in now that the join key is
     * always an integer: a string comparison inside the correlated subquery,
     * under OR and under NOT, where the optimiser cannot turn the EXISTS into
     * a semi-join.
     */
    public function test_a_string_comparison_inside_an_int_keyed_exists_is_exact_under_or_and_not(): void
    {
        $generator = new PredicateTreeGenerator(new Rng(self::SEED));

        foreach (['abc', 'Abc', 'abc ', '', 'zzz'] as $needle) {
            $exists = $generator->stringInsideExists($needle);

            $cases = [
                'alone' => $exists,
                'under or' => Predicate::any(Col::int('c_int')->eq(-7), $exists),
                'under not' => Predicate::not($exists),
                'under not or' => Predicate::not(Predicate::any(Col::int('c_int')->eq(-7), $exists)),
                'or of two' => Predicate::any($exists, Predicate::not($exists)),
                'nested' => Predicate::all(
                    Predicate::any(Col::bool('c_bool')->eq(true), $exists),
                    Predicate::not(Predicate::all(Col::string('c_str')->isNotNull(), $exists)),
                ),
            ];

            foreach ($cases as $label => $predicate) {
                $this->assertSame(
                    Interpreters::sql('fx_matrix', $predicate),
                    Interpreters::runtime('fx_matrix', $predicate),
                    sprintf('%s, needle %s, on %s', $label, var_export($needle, true), $this->dbConnection()),
                );
            }

            // And the answer really is byte-exact, not merely self-consistent.
            $this->assertSame(
                $this->idsWhoseChildKStrEquals($needle),
                Interpreters::sql('fx_matrix', Predicate::any(Predicate::never(), $exists)),
                sprintf('inexact match for %s on %s', var_export($needle, true), $this->dbConnection()),
            );
        }
    }

    /**
     * Section 4.3 as amended: SQLite normalises a datetime to millisecond
     * precision, so two instants that differ only below a millisecond are the
     * same value there. Every other engine distinguishes them.
     */
    public function test_sub_millisecond_datetimes_are_the_documented_engine_limit(): void
    {
        DB::table('fx_matrix')->insert([
            ['id' => 900, 'c_dtf' => '2026-06-01 12:00:00.000100'],
            ['id' => 901, 'c_dtf' => '2026-06-01 12:00:00.000000'],
        ]);

        $exact = new DateTimeImmutable('2026-06-01 12:00:00.000000', new DateTimeZone('UTC'));
        $predicate = Predicate::all(Col::int('id')->gte(900), Col::datetime('c_dtf')->eq($exact));

        // The runtime interpreter compares at full precision on every engine.
        $this->assertSame([901], Interpreters::runtime('fx_matrix', $predicate));

        if ($this->usingRealEngine()) {
            $this->assertSame([901], Interpreters::sql('fx_matrix', $predicate), 'a real engine distinguishes microseconds');

            return;
        }

        $this->assertSame(
            [900, 901],
            Interpreters::sql('fx_matrix', $predicate),
            'SQLite compares datetimes at millisecond precision; this is the documented residual',
        );
    }

    public function test_nested_exists_with_null_and_dangling_keys_agrees(): void
    {
        $predicate = Exists::in('fx_matrix_child')
            ->match('id', 'm_id')
            ->where(Predicate::all(
                Col::int('c_int')->gte(0),
                Exists::in('fx_matrix_grandchild')->match('id', 'child_id')->where(Col::string('c_str')->isNotNull()),
            ));

        $this->assertSame(
            Interpreters::sql('fx_matrix', $predicate),
            Interpreters::runtime('fx_matrix', $predicate),
        );

        $negated = Predicate::not($predicate);

        $this->assertSame(
            Interpreters::sql('fx_matrix', $negated),
            Interpreters::runtime('fx_matrix', $negated),
        );
    }

    public function test_fractional_second_datetimes_agree_for_equality_and_ordering(): void
    {
        $noon = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));

        foreach ([
            'eq' => Col::datetime('c_dtf')->eq($noon),
            'gt' => Col::datetime('c_dtf')->gt($noon),
            'gte' => Col::datetime('c_dtf')->gte($noon),
            'lt' => Col::datetime('c_dtf')->lt($noon),
        ] as $label => $predicate) {
            $this->assertSame(
                Interpreters::sql('fx_matrix', $predicate),
                Interpreters::runtime('fx_matrix', $predicate),
                sprintf('%s over a fractional-second datetime on %s', $label, $this->dbConnection()),
            );
        }
    }

    public function test_boolean_columns_stored_as_zero_and_one_agree(): void
    {
        foreach ([true, false] as $value) {
            $predicate = Col::bool('c_bool')->eq($value);

            $this->assertSame(
                Interpreters::sql('fx_matrix', $predicate),
                Interpreters::runtime('fx_matrix', $predicate),
            );
            $this->assertSame([], array_values(array_intersect(
                Interpreters::sql('fx_matrix', $predicate),
                $this->idsWhereNull('c_bool'),
            )));
        }
    }

    public function test_negation_of_is_null_and_of_exists_agree(): void
    {
        foreach ([
            Predicate::not(Col::int('c_int')->isNull()),
            Predicate::not(Col::string('c_str')->in(['abc', 'Abc'])),
            Predicate::not(Exists::in('fx_matrix_child')->match('id', 'm_id')->where(Col::int('c_int')->eq(0))),
            Predicate::not(Col::int('c_int')->eqCol(Col::int('c_int2'))),
        ] as $predicate) {
            $this->assertSame(
                Interpreters::sql('fx_matrix', $predicate),
                Interpreters::runtime('fx_matrix', $predicate),
                PredicateDescriber::describe($predicate),
            );
        }
    }

    /**
     * The ids whose child rows contain a k_str byte-equal to $value, computed
     * in PHP so it never depends on the engine's collation.
     *
     * @return list<int>
     */
    private function idsWhoseChildKStrEquals(string $value): array
    {
        $parents = [];

        foreach (Interpreters::snapshots('fx_matrix_child') as $child) {
            $raw = $child->get('k_str');
            $parent = $child->get('m_id');

            if (is_string($raw) && $raw === $value && $parent !== null) {
                $parents[(int) $parent] = true;
            }
        }

        $ids = [];

        foreach (Interpreters::snapshots('fx_matrix') as $row) {
            $id = (int) $row->get('id');

            if (isset($parents[$id])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<int> */
    private function idsWhereNull(string $column): array
    {
        $ids = [];

        foreach (Interpreters::snapshots('fx_matrix') as $row) {
            if ($row->get($column) === null) {
                $ids[] = (int) $row->get('id');
            }
        }

        return $ids;
    }

    /**
     * Exact byte comparison done in PHP over the raw rows, so it never depends
     * on the engine's collation.
     *
     * @return list<int>
     */
    private function idsWhereRawEquals(string $column, string $value): array
    {
        $ids = [];

        foreach (Interpreters::snapshots('fx_matrix') as $row) {
            $raw = $row->get($column);

            if (is_string($raw) && $raw === $value) {
                $ids[] = (int) $row->get('id');
            }
        }

        return $ids;
    }
}

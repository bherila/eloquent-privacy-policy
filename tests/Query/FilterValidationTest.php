<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Query;

use BWH\EloquentPrivacyPolicy\Exceptions\PrivacyException;
use BWH\EloquentPrivacyPolicy\Query\FilterGroup;
use BWH\EloquentPrivacyPolicy\Query\ProtectedBuilder;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use DateTimeImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contract section 5.1: "Filter columns must be plain identifiers (optionally
 * table.column on the root table); operators come from a fixed list; values
 * must be scalar, null, DateTimeInterface, or a list of those. Expressions,
 * closures-as-values, builders and subqueries are rejected."
 */
final class FilterValidationTest extends FixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createClinicalSchema();
        ClinicalSeed::seed();
    }

    /** @return ProtectedBuilder<ClinicalRecord> */
    private function protectedQuery(): ProtectedBuilder
    {
        return ClinicalRecord::privacyQuery($this->context(1));
    }

    /** @param callable(): mixed $call */
    private function assertRejected(string $label, callable $call): void
    {
        try {
            $call();
            $this->fail(sprintf('%s was accepted.', $label));
        } catch (Throwable $error) {
            $this->assertTrue(
                $error instanceof PrivacyException || $error instanceof \TypeError,
                sprintf('%s was rejected with %s, which is neither a PrivacyException nor a TypeError', $label, $error::class),
            );
        }
    }

    // ----- columns -----------------------------------------------------------

    public function test_only_plain_columns_of_the_root_table_are_accepted(): void
    {
        $bad = [
            'other table' => 'fx_patients.name',
            'sql fragment' => 'id) or (1=1',
            'comment' => 'id -- ',
            'expression text' => 'count(*)',
            'star' => '*',
            'qualified star' => 'fx_clinical_records.*',
            'empty' => '',
            'space' => 'review status',
            'semicolon' => 'id;drop table fx_patients',
            'backtick' => 'id`',
            'two dots' => 'a.b.c',
        ];

        foreach ($bad as $label => $column) {
            $this->assertRejected('column '.$label, fn () => $this->protectedQuery()->where($column, 1));
            $this->assertRejected('whereIn column '.$label, fn () => $this->protectedQuery()->whereIn($column, [1]));
            $this->assertRejected('whereNull column '.$label, fn () => $this->protectedQuery()->whereNull($column));
            $this->assertRejected('orderBy column '.$label, fn () => $this->protectedQuery()->orderBy($column));
            $this->assertRejected('select column '.$label, fn () => $this->protectedQuery()->select([$column]));
        }
    }

    public function test_a_filter_column_may_name_the_root_table_explicitly(): void
    {
        $this->assertSame([1], $this->protectedQuery()->where('fx_clinical_records.id', 1)->get()->modelKeys());
        $this->assertSame([1], $this->protectedQuery()->whereIn('fx_clinical_records.id', [1, 4])->get()->modelKeys());
    }

    /**
     * Shape columns (select, orderBy, sum) take the same qualified form as
     * filter columns: the root table may be named, nothing else may.
     */
    public function test_shape_columns_accept_the_root_table_and_nothing_else(): void
    {
        $bare = $this->protectedQuery()->select(['review_status'])->orderBy('id')->get();
        $qualified = $this->protectedQuery()
            ->select(['fx_clinical_records.review_status'])
            ->orderBy('fx_clinical_records.id')
            ->get();

        $this->assertSame($bare->modelKeys(), $qualified->modelKeys());
        $this->assertSame(
            array_keys($bare->firstOrFail()->getAttributes()),
            array_keys($qualified->firstOrFail()->getAttributes()),
        );

        $this->createFinanceSchema();
        \BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\FinanceSeed::seed();

        $fees = fn (): ProtectedBuilder => \BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\FeeSchedule::privacyQuery($this->context(1));

        $this->assertEqualsWithDelta($fees()->sum('amount'), $fees()->sum('fx_fee_schedules.amount'), 0.001);
    }

    public function test_shape_columns_reject_every_other_form(): void
    {
        $bad = [
            'another table' => 'fx_patients.name',
            'two dots' => 'fx_clinical_records.a.b',
            'empty segment' => 'fx_clinical_records.',
            'leading dot' => '.id',
            'space' => 'review status',
            'quoted' => '"id"',
            'backticked' => '`id`',
            'parenthesis' => 'count(id)',
            'trailing newline' => "id\n",
            'qualified newline' => "fx_clinical_records.id\n",
            'star' => '*',
            'qualified star' => 'fx_clinical_records.*',
        ];

        foreach ($bad as $label => $column) {
            $this->assertRejected('orderBy '.$label, fn () => $this->protectedQuery()->orderBy($column));
            $this->assertRejected('select '.$label, fn () => $this->protectedQuery()->select([$column]));
            $this->assertRejected('sum '.$label, fn () => $this->protectedQuery()->sum($column));
            $this->assertRejected('where '.$label, fn () => $this->protectedQuery()->where($column, 1));
        }
    }

    public function test_an_expression_can_never_be_a_column(): void
    {
        $this->assertRejected('DB::raw column', fn () => $this->callLoosely($this->protectedQuery(), 'where', [DB::raw('1 = 1'), 1]));
        $this->assertRejected('Expression column', fn () => $this->callLoosely($this->protectedQuery(), 'orderBy', [new Expression('id')]));
    }

    // ----- operators ---------------------------------------------------------

    public function test_only_the_fixed_operator_list_is_accepted(): void
    {
        foreach (['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like', 'LIKE', 'NOT LIKE'] as $operator) {
            $this->assertSame([], array_diff(
                $this->protectedQuery()->where('id', $operator, 1)->get()->modelKeys(),
                ClinicalSeed::RECORDS_VISIBLE_TO_1,
            ), 'operator '.$operator);
        }

        foreach (['rlike', 'regexp', 'sounds like', '<=>', 'is', 'in', 'between', '', ' = ', '=;--', 'like binary'] as $operator) {
            $this->assertRejected('operator '.var_export($operator, true), fn () => $this->protectedQuery()->where('id', $operator, 1));
        }
    }

    public function test_a_non_string_operator_is_rejected(): void
    {
        foreach ([1, true, ['='], null] as $operator) {
            $this->assertRejected('operator '.get_debug_type($operator), fn () => $this->protectedQuery()->where('id', $operator, 1));
        }
    }

    // ----- values ------------------------------------------------------------

    public function test_non_scalar_values_are_rejected(): void
    {
        $bad = [
            'expression' => DB::raw('1'),
            'closure' => static fn (): int => 1,
            'query builder' => DB::table('fx_patients'),
            'eloquent builder' => ClinicalRecord::query(),
            'protected builder' => $this->protectedQuery(),
            'array' => [1, 2],
            'object' => new \stdClass(),
        ];

        foreach ($bad as $label => $value) {
            $this->assertRejected('where value '.$label, fn () => $this->protectedQuery()->where('id', '=', $value));
            $this->assertRejected('whereBetween value '.$label, fn () => $this->protectedQuery()->whereBetween('id', $value, 1));
            $this->assertRejected('nested value '.$label, fn () => $this->protectedQuery()->where(
                fn (FilterGroup $g) => $g->where('id', '=', $value),
            ));
        }
    }

    public function test_a_builder_is_never_a_list_of_values(): void
    {
        foreach ([DB::table('fx_patients'), ClinicalRecord::query(), static fn (): int => 1] as $subquery) {
            $this->assertRejected('whereIn subquery', fn () => $this->callLoosely($this->protectedQuery(), 'whereIn', ['id', $subquery]));
            $this->assertRejected('whereNotIn subquery', fn () => $this->callLoosely($this->protectedQuery(), 'whereNotIn', ['id', $subquery]));
        }

        $this->assertRejected('whereIn nested array', fn () => $this->protectedQuery()->whereIn('id', [[1, 2]]));
        $this->assertRejected('whereIn expression', fn () => $this->protectedQuery()->whereIn('id', [DB::raw('1')]));
    }

    public function test_null_may_only_be_expressed_with_where_null(): void
    {
        $this->assertRejected('where(col, null)', fn () => $this->protectedQuery()->where('patient_id', null));
        $this->assertRejected('where(col, =, null)', fn () => $this->protectedQuery()->where('patient_id', '=', null));
        $this->assertRejected('between null', fn () => $this->protectedQuery()->whereBetween('id', null, 5));

        $this->assertSame([], $this->protectedQuery()->whereNull('patient_id')->get()->modelKeys());
        $this->assertSame(
            ClinicalSeed::RECORDS_VISIBLE_TO_1,
            $this->protectedQuery()->whereNotNull('patient_id')->orderBy('id')->get()->modelKeys(),
        );
    }

    public function test_the_value_types_the_contract_allows_are_accepted(): void
    {
        foreach ([1, 'open', true, 1.5, new DateTimeImmutable('2026-01-01 00:00:00')] as $value) {
            $this->assertSame([], array_diff(
                $this->protectedQuery()->where('id', '=', $value)->get()->modelKeys(),
                ClinicalSeed::RECORDS_VISIBLE_TO_1,
            ));
        }

        $this->assertSame([1], $this->protectedQuery()->whereIn('id', collect([1, 4]))->get()->modelKeys());
    }

    public function test_a_two_argument_where_means_equality(): void
    {
        $this->assertSame([1], $this->protectedQuery()->where('id', 1)->get()->modelKeys());
        $this->assertSame([1], $this->protectedQuery()->where('id', '=', 1)->get()->modelKeys());
        $this->assertRejected('where with one argument', fn () => $this->protectedQuery()->where('id'));
    }

    public function test_an_empty_nested_group_is_dropped_rather_than_widening(): void
    {
        $ids = $this->protectedQuery()->where('id', 1)->orWhere(static function (FilterGroup $group): void {
            // deliberately adds nothing
        })->orderBy('id')->get()->modelKeys();

        $this->assertSame([1], $ids);
    }

    public function test_eager_loads_are_one_level_and_uncoloned(): void
    {
        $this->assertRejected('nested eager load', fn () => $this->protectedQuery()->with(['patient.records']));
        $this->assertRejected('column constrained eager load', fn () => $this->protectedQuery()->with(['patient:id,name']));
        $this->assertRejected('unknown relation', fn () => $this->protectedQuery()->with(['nope']));
    }
}

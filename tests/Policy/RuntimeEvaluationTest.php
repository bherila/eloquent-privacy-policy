<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Policy;

use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\AttributeTypeMismatch;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingAttribute;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingFact;
use BWH\EloquentPrivacyPolicy\Exceptions\PrivacyException;
use BWH\EloquentPrivacyPolicy\Exceptions\StageEvaluationFailed;
use BWH\EloquentPrivacyPolicy\Policy\PolicyResolver;
use BWH\EloquentPrivacyPolicy\Policy\StageReducer;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\ColType;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Runtime\ExistsFacts;
use BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator;
use BWH\EloquentPrivacyPolicy\Runtime\RelationFactLoader;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Matrix\MatrixSeed;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/** Contract sections 4.2, 4.3 and 4.4: the runtime interpreter's own errors. */
final class RuntimeEvaluationTest extends FixtureTestCase
{
    // ----- absent attributes -------------------------------------------------

    public function test_an_absent_attribute_is_an_error_and_never_a_null(): void
    {
        $row = new RowSnapshot(['id' => 1, 'present' => null]);

        $this->assertTrue($row->has('present'));
        $this->assertNull($row->get('present'));
        $this->assertFalse($row->has('absent'));

        try {
            $row->get('absent');
            $this->fail('An absent column was read.');
        } catch (MissingAttribute $exception) {
            $this->assertStringContainsString('not treated as NULL', $exception->getMessage());
        }
    }

    public function test_every_node_that_reads_a_column_raises_missing_attribute(): void
    {
        $row = new RowSnapshot(['id' => 1]);
        $facts = new ExistsFacts();
        $evaluator = new PredicateEvaluator();

        $nodes = [
            'eq' => Col::int('gone')->eq(1),
            'in' => Col::int('gone')->in([1]),
            'isNull' => Col::int('gone')->isNull(),
            'isNotNull' => Col::int('gone')->isNotNull(),
            'eqCol' => Col::int('gone')->eqCol(Col::int('id')),
            'not' => Predicate::not(Col::int('gone')->eq(1)),
            'all' => Predicate::all(Col::int('gone')->eq(1), Col::int('id')->eq(1)),
            'any' => Predicate::any(Col::int('gone')->eq(1), Col::int('id')->eq(1)),
        ];

        foreach ($nodes as $label => $node) {
            try {
                $evaluator->evaluate($node, $row, $facts);
                $this->fail(sprintf('%s treated an absent column as a value.', $label));
            } catch (MissingAttribute) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_partial_select_fails_the_stage_rather_than_denying_quietly(): void
    {
        $this->createClinicalSchema();
        ClinicalSeed::seed();

        $context = $this->context(1);
        $resolved = (new PolicyResolver())->resolveRead(ClinicalRecord::class, $context);
        $facts = (new RelationFactLoader(DB::connection()))->prepare($resolved->toPredicate(), []);

        try {
            $resolved->decide(
                new RowSnapshot(['id' => 1, 'review_status' => 'open']), // patient_id missing
                $facts,
                new PredicateEvaluator(),
                new StageReducer(),
            );
            $this->fail('A partial snapshot silently produced a decision.');
        } catch (StageEvaluationFailed $failure) {
            $this->assertInstanceOf(MissingAttribute::class, $failure->getPrevious());
        }
    }

    public function test_a_disjunction_still_errors_even_when_an_earlier_child_is_true(): void
    {
        $evaluator = new PredicateEvaluator();
        $row = new RowSnapshot(['id' => 1]);

        // Child order must not decide whether an error is raised.
        foreach ([
            Predicate::any(Col::int('id')->eq(1), Col::int('gone')->eq(1)),
            Predicate::any(Col::int('gone')->eq(1), Col::int('id')->eq(1)),
        ] as $predicate) {
            try {
                $evaluator->evaluate($predicate, $row, new ExistsFacts());
                $this->fail('A true sibling masked a missing attribute.');
            } catch (MissingAttribute) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ----- raw value normalisation ------------------------------------------

    public function test_an_unnormalisable_raw_value_is_an_attribute_type_mismatch(): void
    {
        $evaluator = new PredicateEvaluator();

        $cases = [
            'int/word' => [Col::int('v')->eq(1), 'abc'],
            'int/decimal' => [Col::int('v')->eq(1), '1.5'],
            'int/float' => [Col::int('v')->eq(1), 1.5],
            'int/bool' => [Col::int('v')->eq(1), true],
            'string/int' => [Col::string('v')->eq('1'), 1],
            'string/float' => [Col::string('v')->eq('1'), 1.5],
            'bool/two' => [Col::bool('v')->eq(true), 2],
            'bool/yes' => [Col::bool('v')->eq(true), 'yes'],
            'bool/empty' => [Col::bool('v')->eq(true), ''],
            'datetime/garbage' => [Col::datetime('v')->eq(new DateTimeImmutable()), 'not a date'],
            'datetime/date only' => [Col::datetime('v')->eq(new DateTimeImmutable()), '2026-06-01'],
            'datetime/int' => [Col::datetime('v')->eq(new DateTimeImmutable()), 1767225600],
            'datetime/overflow' => [Col::datetime('v')->eq(new DateTimeImmutable()), '2026-13-45 99:99:99'],
        ];

        foreach ($cases as $label => [$predicate, $raw]) {
            try {
                $evaluator->evaluate($predicate, new RowSnapshot(['v' => $raw]), new ExistsFacts());
                $this->fail(sprintf('%s was silently normalised.', $label));
            } catch (AttributeTypeMismatch $exception) {
                $this->assertStringContainsString('cannot be read as', $exception->getMessage());
            }
        }
    }

    public function test_the_raw_values_the_contract_names_are_accepted(): void
    {
        $evaluator = new PredicateEvaluator();
        $noon = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));

        $accepted = [
            [Col::int('v')->eq(-3), -3],
            [Col::int('v')->eq(-3), '-3'],
            [Col::int('v')->eq(0), '0'],
            [Col::bool('v')->eq(true), true],
            [Col::bool('v')->eq(true), 1],
            [Col::bool('v')->eq(true), '1'],
            [Col::bool('v')->eq(false), 0],
            [Col::bool('v')->eq(false), '0'],
            [Col::string('v')->eq('abc '), 'abc '],
            [Col::datetime('v')->eq($noon), '2026-06-01 12:00:00'],
            [Col::datetime('v')->eq($noon), '2026-06-01 12:00:00.000000'],
            [Col::datetime('v')->eq($noon), $noon],
        ];

        foreach ($accepted as [$predicate, $raw]) {
            $this->assertTrue(
                $evaluator->evaluate($predicate, new RowSnapshot(['v' => $raw]), new ExistsFacts()),
                sprintf('raw %s was not accepted', var_export($raw, true)),
            );
        }
    }

    public function test_a_datetime_in_another_zone_is_read_as_the_same_instant(): void
    {
        $evaluator = new PredicateEvaluator();
        $noon = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
        $raw = new DateTimeImmutable('2026-06-01 17:45:00', new DateTimeZone('Asia/Kathmandu'));

        $this->assertTrue($evaluator->evaluate(
            Col::datetime('v')->eq($noon),
            new RowSnapshot(['v' => $raw]),
            new ExistsFacts(),
        ));
    }

    public function test_string_comparison_at_runtime_is_byte_exact(): void
    {
        $evaluator = new PredicateEvaluator();

        foreach ([['abc', 'Abc'], ['abc', 'abc '], ['abc', 'ABC'], ['', ' ']] as [$policy, $raw]) {
            $this->assertFalse(
                $evaluator->evaluate(Col::string('v')->eq($policy), new RowSnapshot(['v' => $raw]), new ExistsFacts()),
                sprintf('%s and %s must not be equal', var_export($policy, true), var_export($raw, true)),
            );
        }

        $this->assertSame(0, ColType::String->compare('abc', 'abc'));
        $this->assertNotSame(0, ColType::String->compare('abc', 'Abc'));
    }

    // ----- EXISTS facts ------------------------------------------------------

    public function test_evaluating_an_unprepared_exists_is_an_error(): void
    {
        $node = $this->existsNode();
        $evaluator = new PredicateEvaluator();

        $this->assertFalse((new ExistsFacts())->isPrepared($node));

        foreach ([['id' => 1], ['id' => null]] as $attributes) {
            try {
                $evaluator->evaluate($node, new RowSnapshot($attributes), new ExistsFacts());
                $this->fail('An unprepared EXISTS was evaluated.');
            } catch (MissingFact $error) {
                $this->assertStringContainsString('was not batch-prepared', $error->getMessage());
                $this->assertInstanceOf(PrivacyException::class, $error);
            }
        }
    }

    public function test_preparing_a_different_node_object_does_not_prepare_this_one(): void
    {
        $this->createMatrixSchema();
        MatrixSeed::seed();

        $prepared = $this->existsNode();
        $other = $this->existsNode();

        $facts = (new RelationFactLoader(DB::connection()))->prepare($prepared, []);

        $this->assertTrue($facts->isPrepared($prepared));
        $this->assertFalse($facts->isPrepared($other), 'facts are keyed per node, not per shape');
    }

    public function test_preparing_with_no_rows_still_marks_the_node_prepared_and_issues_no_query(): void
    {
        $this->createMatrixSchema();
        MatrixSeed::seed();

        $node = $this->existsNode();

        [$facts, $queries] = $this->countingQueries(
            fn (): ExistsFacts => (new RelationFactLoader(DB::connection()))->prepare($node, []),
        );

        $this->assertTrue($facts->isPrepared($node));
        $this->assertSame([], $queries);
        $this->assertFalse((new PredicateEvaluator())->evaluate($node, new RowSnapshot(['id' => 1]), $facts));
    }

    public function test_an_exists_with_a_null_outer_key_is_false_and_not_an_error(): void
    {
        $this->createMatrixSchema();
        MatrixSeed::seed();

        $node = $this->existsNode();
        $rows = [new RowSnapshot(['id' => null]), new RowSnapshot(['id' => 1])];
        $facts = (new RelationFactLoader(DB::connection()))->prepare($node, $rows);
        $evaluator = new PredicateEvaluator();

        $this->assertFalse($evaluator->evaluate($node, $rows[0], $facts));
        $this->assertTrue($evaluator->evaluate($node, $rows[1], $facts));
        $this->assertTrue($evaluator->evaluate(Predicate::not($node), $rows[0], $facts));
    }

    public function test_every_runtime_error_is_a_privacy_exception(): void
    {
        foreach ([
            new MissingAttribute('x'),
            new AttributeTypeMismatch('x'),
            new StageEvaluationFailed('grant', []),
            new MissingFact('x'),
        ] as $exception) {
            $this->assertInstanceOf(PrivacyException::class, $exception);
        }
    }

    /** An unconditional correlated EXISTS over the matrix child table. */
    private function existsNode(): Exists
    {
        $node = Exists::in('fx_matrix_child')->match('id', 'm_id')->where(Predicate::always());

        $this->assertInstanceOf(Exists::class, $node);

        return $node;
    }

    public function test_the_runtime_reduction_follows_the_same_stage_table(): void
    {
        $this->createClinicalSchema();
        ClinicalSeed::seed();

        $context = $this->context(1);
        $resolved = (new PolicyResolver())->resolveRead(ClinicalRecord::class, $context);
        $rows = [];

        foreach (DB::table('fx_clinical_records')->orderBy('id')->get() as $row) {
            $rows[] = new RowSnapshot((array) $row);
        }

        $facts = (new RelationFactLoader(DB::connection()))->prepare($resolved->toPredicate(), $rows);
        $allowed = [];

        foreach ($rows as $row) {
            if ($resolved->decide($row, $facts, new PredicateEvaluator(), new StageReducer()) === Decision::Allow) {
                $allowed[] = (int) $row->get('id');
            }
        }

        // The runtime sees soft-deleted rows too; the SQL path filters them by
        // the model's global scope, so record 7 is the only difference.
        $this->assertSame([1, 3, 7, 10, 11], $allowed);
    }
}

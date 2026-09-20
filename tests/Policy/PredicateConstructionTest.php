<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Policy;

use BWH\EloquentPrivacyPolicy\Exceptions\AttributeTypeMismatch;
use BWH\EloquentPrivacyPolicy\Exceptions\InvalidIdentifier;
use BWH\EloquentPrivacyPolicy\Exceptions\UncompilablePolicy;
use BWH\EloquentPrivacyPolicy\Predicate\AllOf;
use BWH\EloquentPrivacyPolicy\Predicate\AnyOf;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\ColType;
use BWH\EloquentPrivacyPolicy\Predicate\ColumnComparison;
use BWH\EloquentPrivacyPolicy\Predicate\Comparison;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\InList;
use BWH\EloquentPrivacyPolicy\Predicate\Negation;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use BWH\EloquentPrivacyPolicy\Query\FilterGroup;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\Patient;
use BWH\EloquentPrivacyPolicy\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/** Contract section 4.1 and 4.3: what the IR accepts at construction time. */
final class PredicateConstructionTest extends TestCase
{
    /** @return list<array{Col, mixed, string}> */
    public static function badPolicyValues(): array
    {
        return [
            [Col::int('a'), null, 'int/null'],
            [Col::int('a'), '5', 'int/numeric string'],
            [Col::int('a'), 5.0, 'int/float'],
            [Col::int('a'), true, 'int/bool'],
            [Col::string('a'), null, 'string/null'],
            [Col::string('a'), 5, 'string/int'],
            [Col::string('a'), true, 'string/bool'],
            [Col::bool('a'), null, 'bool/null'],
            [Col::bool('a'), 1, 'bool/int'],
            [Col::bool('a'), '1', 'bool/string'],
            [Col::datetime('a'), null, 'datetime/null'],
            [Col::datetime('a'), '2026-01-01 00:00:00', 'datetime/string'],
            [Col::datetime('a'), 1767225600, 'datetime/timestamp'],
            [Col::int('a'), new stdClass(), 'int/object'],
        ];
    }

    #[DataProvider('badPolicyValues')]
    public function test_a_policy_value_of_the_wrong_type_is_rejected_at_construction(Col $column, mixed $value, string $label): void
    {
        $this->expectException(AttributeTypeMismatch::class);
        $this->expectExceptionMessage('is declared');

        $column->eq($value);
    }

    #[DataProvider('badPolicyValues')]
    public function test_a_list_member_of_the_wrong_type_is_rejected_too(Col $column, mixed $value, string $label): void
    {
        $this->expectException(AttributeTypeMismatch::class);

        $column->in([$value]);
    }

    public function test_null_is_never_a_comparable_value_on_any_type(): void
    {
        foreach ([Col::int('a'), Col::string('a'), Col::bool('a'), Col::datetime('a')] as $column) {
            foreach ([fn () => $column->eq(null), fn () => $column->in([null])] as $build) {
                try {
                    $build();
                    $this->fail('null was accepted as a comparison value.');
                } catch (AttributeTypeMismatch) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_ordered_comparison_is_offered_for_int_and_datetime_only(): void
    {
        $this->assertInstanceOf(Comparison::class, Col::int('a')->lt(1));
        $this->assertInstanceOf(Comparison::class, Col::datetime('a')->gte(new DateTimeImmutable()));
        $this->assertTrue(ColType::Int->supportsOrdering());
        $this->assertTrue(ColType::Datetime->supportsOrdering());
        $this->assertFalse(ColType::String->supportsOrdering());
        $this->assertFalse(ColType::Bool->supportsOrdering());

        foreach ([Col::string('a'), Col::bool('a')] as $column) {
            foreach (['lt', 'lte', 'gt', 'gte'] as $method) {
                try {
                    $column->{$method}('x');
                    $this->fail(sprintf('%s() was offered for a %s column.', $method, $column->type->value));
                } catch (AttributeTypeMismatch $exception) {
                    $this->assertStringContainsString('no ordered comparison', $exception->getMessage());
                }
            }
        }
    }

    public function test_eq_col_requires_the_same_declared_type_on_both_sides(): void
    {
        $this->assertInstanceOf(ColumnComparison::class, Col::int('a')->eqCol(Col::int('b')));
        $this->assertInstanceOf(ColumnComparison::class, Col::string('a')->eqCol(Col::string('b')));

        foreach ([
            [Col::int('a'), Col::string('b')],
            [Col::string('a'), Col::bool('b')],
            [Col::datetime('a'), Col::int('b')],
            [Col::bool('a'), Col::datetime('b')],
        ] as [$left, $right]) {
            try {
                $left->eqCol($right);
                $this->fail('eqCol() joined two different declared types.');
            } catch (AttributeTypeMismatch $exception) {
                $this->assertStringContainsString('different declared types', $exception->getMessage());
            }
        }
    }

    public function test_in_with_an_empty_list_folds_to_never(): void
    {
        foreach ([Col::int('a'), Col::string('a'), Col::bool('a'), Col::datetime('a')] as $column) {
            $this->assertTrue($column->in([])->isNever());
        }

        $this->assertInstanceOf(InList::class, Col::int('a')->in([1]));
    }

    public function test_column_and_table_names_must_be_plain_identifiers(): void
    {
        $bad = ['', 'a b', 'a-b', 'a.b', '1a', 'a;drop', 'a`b', '*', 'a)', "a\tb", "a\nb"];

        foreach ($bad as $name) {
            foreach ([fn () => Col::int($name), fn () => Exists::in($name), fn () => ViaParent::of('fk', Patient::class, $name)] as $build) {
                try {
                    $build();
                    $this->fail(sprintf('"%s" was accepted as an identifier.', $name));
                } catch (InvalidIdentifier) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    /**
     * Section 4.1: identifiers are "validated as identifiers". The regex in
     * Identifier::assert() is anchored with $, which in PCRE also matches
     * immediately before a final newline, so a name ending in "\n" passes
     * validation and is handed to the grammar verbatim.
     */
    public function test_an_identifier_may_not_end_in_a_newline(): void
    {
        $this->expectException(InvalidIdentifier::class);

        Col::int("owner_id\n");
    }

    /** The same anchoring gap in the caller-facing filter column validator. */
    public function test_a_filter_column_may_not_end_in_a_newline(): void
    {
        $this->expectException(InvalidIdentifier::class);

        (new FilterGroup('fx_questions'))->where("title\n", 'x');
    }

    public function test_an_exists_must_be_correlated(): void
    {
        $this->assertInstanceOf(Exists::class, Exists::in('t')->match('a', 'b')->where(Predicate::always()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one match');

        Exists::in('t')->where(Predicate::always());
    }

    /**
     * Section 4.1: the correlation key is an integer on both sides. Since a
     * non-integer key is refused first, a *mismatched* pair can only ever be
     * int/non-int, so it is an UncompilablePolicy rather than the
     * AttributeTypeMismatch the node also knows how to raise.
     */
    public function test_an_exists_key_must_be_an_integer_on_both_sides(): void
    {
        foreach ([
            'string/string' => [Col::string('a'), Col::string('b')],
            'bool/bool' => [Col::bool('a'), Col::bool('b')],
            'datetime/datetime' => [Col::datetime('a'), Col::datetime('b')],
            'int/string' => [Col::int('a'), Col::string('b')],
            'string/int' => [Col::string('a'), Col::int('b')],
            'int/datetime' => [Col::int('a'), Col::datetime('b')],
        ] as $label => [$outer, $inner]) {
            try {
                Exists::in('t')->matchCols($outer, $inner)->where(Predicate::always());
                $this->fail(sprintf('an EXISTS correlated on %s keys was accepted', $label));
            } catch (UncompilablePolicy $exception) {
                $this->assertStringContainsString('only integer keys are supported', $exception->getMessage());
            }
        }

        $this->assertInstanceOf(
            Exists::class,
            Exists::in('t')->matchCols(Col::int('a'), Col::int('b'))->where(Predicate::always()),
        );
    }

    public function test_an_exists_whose_inner_predicate_can_never_hold_folds_to_never(): void
    {
        $this->assertTrue(Exists::in('t')->match('a', 'b')->where(Predicate::never())->isNever());
        $this->assertTrue(Exists::in('t')->match('a', 'b')->where(Col::int('x')->in([]))->isNever());
    }

    public function test_constants_fold_through_conjunction_disjunction_and_negation(): void
    {
        $leaf = Col::int('a')->eq(1);

        $this->assertTrue(Predicate::all()->isAlways());
        $this->assertTrue(Predicate::any()->isNever());
        $this->assertTrue(Predicate::all(Predicate::never(), $leaf)->isNever());
        $this->assertTrue(Predicate::any(Predicate::always(), $leaf)->isAlways());
        $this->assertSame($leaf, Predicate::all(Predicate::always(), $leaf));
        $this->assertSame($leaf, Predicate::any(Predicate::never(), $leaf));
        $this->assertTrue(Predicate::not(Predicate::always())->isNever());
        $this->assertTrue(Predicate::not(Predicate::never())->isAlways());
        $this->assertSame($leaf, Predicate::not(Predicate::not($leaf)), 'double negation must collapse');

        $this->assertInstanceOf(AllOf::class, Predicate::all($leaf, Col::int('b')->eq(2)));
        $this->assertInstanceOf(AnyOf::class, Predicate::any($leaf, Col::int('b')->eq(2)));
        $this->assertInstanceOf(Negation::class, Predicate::not($leaf));
    }

    public function test_a_datetime_policy_value_is_normalised_to_utc_whole_seconds(): void
    {
        $value = new DateTimeImmutable('2026-06-01 18:19:56.789012', new DateTimeZone('Asia/Kathmandu'));

        $predicate = Col::datetime('a')->eq($value);

        $this->assertInstanceOf(Comparison::class, $predicate);
        $this->assertInstanceOf(DateTimeImmutable::class, $predicate->value);
        $this->assertSame('2026-06-01 12:34:56', $predicate->value->format('Y-m-d H:i:s'));
        $this->assertSame('000000', $predicate->value->format('u'));
        $this->assertSame('2026-06-01 12:34:56', ColType::Datetime->binding($predicate->value));
    }

    public function test_booleans_bind_as_zero_and_one(): void
    {
        $this->assertSame(1, ColType::Bool->binding(true));
        $this->assertSame(0, ColType::Bool->binding(false));
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Query;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Query\FilterGroup;
use BWH\EloquentPrivacyPolicy\Query\ProtectedBuilder;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\Patient;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\Account;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\FeeSchedule;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\FinanceSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd\GuardedOverride;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\AuditNote;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\QaSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Question;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Contract section 5: the protected query boundary. */
final class ProtectedBuilderTest extends FixtureTestCase
{
    /** @var list<int> */
    private const array RECORDS = ClinicalSeed::RECORDS_VISIBLE_TO_1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClinicalSchema();
        ClinicalSeed::seed();
    }

    // ----- callers cannot widen ---------------------------------------------

    public function test_no_arrangement_of_caller_filters_can_widen_the_result(): void
    {
        $context = $this->context(1);

        $shapes = [
            'or everything' => fn (ProtectedBuilder $q) => $q->orWhere('id', '>', 0),
            'or on a hidden row' => fn (ProtectedBuilder $q) => $q->orWhere('id', 4),
            'or null' => fn (ProtectedBuilder $q) => $q->where('id', 1)->orWhere(
                fn (FilterGroup $g) => $g->whereNotNull('patient_id'),
            ),
            'nested or group' => fn (ProtectedBuilder $q) => $q->where('id', 1)->orWhere(
                fn (FilterGroup $g) => $g->where('review_status', 'open')->orWhereNull('patient_id'),
            ),
            'deeply nested' => fn (ProtectedBuilder $q) => $q->where(
                fn (FilterGroup $g) => $g->orWhere(
                    fn (FilterGroup $inner) => $inner->orWhereIn('id', [1, 2, 4, 5, 6, 8, 9])->orWhereNull('patient_id'),
                ),
            ),
            'whereIn everything' => fn (ProtectedBuilder $q) => $q->whereIn('id', range(1, 20)),
            'whereNotIn nothing' => fn (ProtectedBuilder $q) => $q->whereNotIn('id', []),
            'whereNull on the key' => fn (ProtectedBuilder $q) => $q->whereNull('patient_id'),
            'between everything' => fn (ProtectedBuilder $q) => $q->whereBetween('id', 0, 1000),
            'between reversed' => fn (ProtectedBuilder $q) => $q->whereBetween('id', 1000, 0),
            'like' => fn (ProtectedBuilder $q) => $q->orWhere('body', 'like', '%'),
        ];

        foreach ($shapes as $label => $shape) {
            $query = ClinicalRecord::privacyQuery($context);
            $shape($query);

            $ids = $query->orderBy('id')->get()->modelKeys();

            $this->assertSame([], array_diff($ids, self::RECORDS), sprintf('"%s" widened the result', $label));
        }
    }

    public function test_an_or_only_filter_still_narrows(): void
    {
        $ids = ClinicalRecord::privacyQuery($this->context(1))
            ->orWhere('review_status', 'open')
            ->orderBy('id')
            ->get()
            ->modelKeys();

        $this->assertSame([1, 3], $ids);
    }

    public function test_filters_narrow_the_visible_set_rather_than_replacing_it(): void
    {
        $query = ClinicalRecord::privacyQuery($this->context(1))->where('review_status', 'open')->orderBy('id');

        $this->assertSame([1, 3], $query->get()->modelKeys());
    }

    // ----- the aggregates all agree -----------------------------------------

    public function test_get_count_exists_and_paginate_agree_on_the_same_allowed_set(): void
    {
        foreach ([1, 2, 99] as $viewer) {
            $context = $this->context($viewer);
            $expected = ClinicalRecord::privacyQuery($context)->orderBy('id')->get()->modelKeys();

            $this->assertSame(count($expected), ClinicalRecord::privacyQuery($context)->count());
            $this->assertSame($expected !== [], ClinicalRecord::privacyQuery($context)->exists());
            $this->assertSame(count($expected), ClinicalRecord::privacyQuery($context)->paginate(200)->total());

            $paged = [];

            for ($page = 1; $page <= 5; $page++) {
                $paged = array_merge($paged, $this->keysOf(ClinicalRecord::privacyQuery($context)->orderBy('id')->paginate(1, $page)->items()));
            }

            $this->assertSame($expected, $paged);
        }
    }

    public function test_sum_agrees_with_the_visible_rows_on_a_decimal_column(): void
    {
        $this->createFinanceSchema();
        FinanceSeed::seed();

        $context = $this->context(1);

        $this->assertSame(FinanceSeed::FEES_VISIBLE_TO_1, FeeSchedule::privacyQuery($context)->orderBy('id')->get()->modelKeys());
        $this->assertEqualsWithDelta(FinanceSeed::FEE_SUM_FOR_1, FeeSchedule::privacyQuery($context)->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(0.0, FeeSchedule::privacyQuery($this->context(99))->sum('amount'), 0.001);
    }

    public function test_aggregates_ignore_soft_deleted_rows_and_soft_deleted_parents(): void
    {
        $context = $this->context(1);

        // Record 7 is soft-deleted; record 6's parent patient is soft-deleted.
        $this->assertSame(count(self::RECORDS), ClinicalRecord::privacyQuery($context)->count());
        $this->assertNull(ClinicalRecord::privacyQuery($context)->find(7));
        $this->assertNull(ClinicalRecord::privacyQuery($context)->find(6));
        $this->assertSame(count(ClinicalSeed::PATIENTS_VISIBLE_TO_1), Patient::privacyQuery($context)->count());
    }

    // ----- find --------------------------------------------------------------

    public function test_an_invisible_id_behaves_exactly_like_a_missing_id(): void
    {
        $context = $this->context(1);

        foreach ([4, 999999] as $id) {
            $this->assertNull(ClinicalRecord::privacyQuery($context)->find($id));

            try {
                ClinicalRecord::privacyQuery($context)->findOrFail($id);
                $this->fail('findOrFail() returned an invisible row.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(ClinicalRecord::class, $exception->getModel());
            }
        }

        $this->assertSame(1, ClinicalRecord::privacyQuery($context)->findOrFail(1)->getKey());
    }

    public function test_first_or_fail_behaves_the_same_way(): void
    {
        $this->assertNull(ClinicalRecord::privacyQuery($this->context(99))->first());

        $this->expectException(ModelNotFoundException::class);
        ClinicalRecord::privacyQuery($this->context(99))->firstOrFail();
    }

    public function test_find_works_on_a_model_with_a_custom_primary_key(): void
    {
        $this->createFinanceSchema();
        FinanceSeed::seed();

        $context = $this->context(1);

        $this->assertSame(2, Account::privacyQuery($context)->findOrFail(2)->getKey());
        $this->assertNull(Account::privacyQuery($context)->find(4));
        $this->assertSame([1, 2, 3], Account::privacyQuery($context)->orderBy('acct_id')->get()->modelKeys());
        $this->assertSame(1, Account::privacyQuery($context)->where('acct_id', 1)->count());
    }

    // ----- shape -------------------------------------------------------------

    public function test_select_always_keeps_the_primary_key(): void
    {
        $model = ClinicalRecord::privacyQuery($this->context(1))->select(['review_status'])->findOrFail(1);

        $this->assertSame(1, $model->getKey());
        $this->assertSame(['id', 'review_status'], array_keys($model->getAttributes()));
    }

    public function test_select_keeps_the_custom_primary_key(): void
    {
        $this->createFinanceSchema();
        FinanceSeed::seed();

        $model = Account::privacyQuery($this->context(1))->select(['label'])->findOrFail(1);

        $this->assertSame(1, $model->getKey());
        $this->assertArrayHasKey('acct_id', $model->getAttributes());
    }

    public function test_order_by_and_limit_and_offset_only_reshape_the_allowed_set(): void
    {
        $context = $this->context(1);

        $this->assertSame(
            array_reverse(self::RECORDS),
            ClinicalRecord::privacyQuery($context)->orderBy('id', 'DESC')->get()->modelKeys(),
        );
        $this->assertSame([self::RECORDS[0]], ClinicalRecord::privacyQuery($context)->orderBy('id')->limit(1)->get()->modelKeys());
        $this->assertSame(
            array_slice(self::RECORDS, 1),
            ClinicalRecord::privacyQuery($context)->orderBy('id')->offset(1)->limit(100)->get()->modelKeys(),
        );
        $this->assertSame([], ClinicalRecord::privacyQuery($context)->orderBy('id')->limit(0)->get()->modelKeys());
        $this->assertSame(
            self::RECORDS,
            ClinicalRecord::privacyQuery($context)->orderBy('id')->offset(-5)->limit(PHP_INT_MAX)->get()->modelKeys(),
            'a negative offset must clamp, never wrap',
        );
    }

    public function test_an_unknown_order_direction_is_rejected(): void
    {
        $this->expectException(\BWH\EloquentPrivacyPolicy\Exceptions\InvalidIdentifier::class);

        ClinicalRecord::privacyQuery($this->context(1))->orderBy('id', 'asc; drop table fx_patients');
    }

    // ----- pagination bounds -------------------------------------------------

    public function test_pagination_bounds_are_enforced(): void
    {
        $context = $this->context(1);

        foreach ([0, -1, ProtectedBuilder::MAX_PER_PAGE + 1, PHP_INT_MAX] as $perPage) {
            try {
                ClinicalRecord::privacyQuery($context)->paginate($perPage);
                $this->fail(sprintf('perPage %d was accepted.', $perPage));
            } catch (UnsupportedProtectedOperation $exception) {
                $this->assertStringContainsString('perPage must be between', $exception->getMessage());
            }
        }

        $this->assertSame(count(self::RECORDS), ClinicalRecord::privacyQuery($context)->paginate(ProtectedBuilder::MAX_PER_PAGE)->total());
    }

    public function test_pagination_cannot_be_combined_with_limit_or_offset(): void
    {
        $context = $this->context(1);

        foreach ([
            fn () => ClinicalRecord::privacyQuery($context)->limit(1)->paginate(10),
            fn () => ClinicalRecord::privacyQuery($context)->offset(1)->paginate(10),
        ] as $call) {
            try {
                $call();
                $this->fail('paginate() was combined with limit()/offset().');
            } catch (UnsupportedProtectedOperation $exception) {
                $this->assertStringContainsString('cannot be combined', $exception->getMessage());
            }
        }
    }

    public function test_an_out_of_range_page_returns_nothing_and_never_more(): void
    {
        $context = $this->context(1);

        foreach ([0, -3, 1000] as $page) {
            $paginator = ClinicalRecord::privacyQuery($context)->orderBy('id')->paginate(2, $page);

            $this->assertSame(count(self::RECORDS), $paginator->total(), 'the total must stay the allowed count');
            $this->assertSame([], array_diff($this->keysOf($paginator->items()), self::RECORDS));
        }
    }

    /**
     * paginate() clamps the page from below (max(1, $page)) but not from
     * above, so a page number the signature accepts overflows when it is
     * multiplied by perPage. Nothing is disclosed, but the caller gets a PHP
     * integer-overflow error instead of one of the contract's exceptions.
     */
    public function test_an_enormous_page_number_is_rejected_cleanly(): void
    {
        $context = $this->context(1);

        try {
            $paginator = ClinicalRecord::privacyQuery($context)->orderBy('id')->paginate(2, PHP_INT_MAX);

            $this->assertSame([], $this->keysOf($paginator->items()));
        } catch (UnsupportedProtectedOperation) {
            $this->addToAssertionCount(1);
        }
    }

    // ----- entry points ------------------------------------------------------

    public function test_the_registry_entry_point_matches_the_trait_one(): void
    {
        $context = $this->context(1);

        $this->assertSame(
            ClinicalRecord::privacyQuery($context)->orderBy('id')->get()->modelKeys(),
            Privacy::query(ClinicalRecord::class, $context)->orderBy('id')->get()->modelKeys(),
        );
    }

    public function test_a_model_without_the_trait_is_refused(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $this->expectException(UnsupportedProtectedOperation::class);
        $this->expectExceptionMessage('HasPrivacyPolicy');

        Privacy::query(AuditNote::class, $this->context(1));
    }

    public function test_a_model_that_overrides_a_guarded_method_is_refused_outright(): void
    {
        $this->createOddSchema();

        try {
            GuardedOverride::privacyQuery($this->context(1));
            $this->fail('A model with a disabled guard was accepted.');
        } catch (UnsupportedProtectedOperation $exception) {
            $this->assertStringContainsString('overrides guarded method(s) save', $exception->getMessage());
        }

        // The audit is about the protected path only: ordinary use is untouched.
        $this->assertSame(0, GuardedOverride::query()->count());
    }

    public function test_cloning_a_builder_does_not_share_the_callers_filters(): void
    {
        $context = $this->context(1);

        $base = ClinicalRecord::privacyQuery($context);
        $narrowed = (clone $base)->where('id', 1);

        $this->assertSame([1], $narrowed->orderBy('id')->get()->modelKeys());
        $this->assertSame(self::RECORDS, $base->orderBy('id')->get()->modelKeys());
    }

    public function test_two_contexts_in_a_row_never_leak_into_one_another(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $one = $this->qaContext(1);
        $two = $this->qaContext(2);

        $first = Question::privacyQuery($one)->orderBy('id')->get()->modelKeys();
        $second = Question::privacyQuery($two)->orderBy('id')->get()->modelKeys();
        $firstAgain = Question::privacyQuery($one)->orderBy('id')->get()->modelKeys();

        $this->assertSame($first, $firstAgain);
        $this->assertNotSame($first, $second);
        $this->assertSame(QaSeed::VISIBLE_TO_1, $first);
    }

    /**
     * @param array<int, ClinicalRecord> $models
     * @return list<int|string|null>
     */
    private function keysOf(array $models): array
    {
        return array_values(array_map(static fn (ClinicalRecord $model): int|string|null => $model->getKey(), $models));
    }

    private function qaContext(int $viewer): PrivacyContext
    {
        return $this->context(
            $viewer,
            ['is_moderator' => false, 'eligible_organization_ids' => [10]],
            ['workspace_id' => 1],
        );
    }
}

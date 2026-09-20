<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Query;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\PolicyResolver;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Policy\StageReducer;
use BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator;
use BWH\EloquentPrivacyPolicy\Runtime\RelationFactLoader;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\FeeSchedule;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Question;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Contract section 4.4: "The number of queries depends on the policy shape,
 * not on the number of rows." Every case below is measured with DB::listen at
 * two very different row counts and must come out identical.
 */
final class QueryCountTest extends FixtureTestCase
{
    private const int SMALL = 10;

    private const int LARGE = 100;

    // ----- clinical ----------------------------------------------------------

    public function test_the_clinical_domain_costs_the_same_for_ten_and_a_hundred_rows(): void
    {
        $this->assertRowCountIndependent(
            fn (int $n) => $this->seedClinical($n),
            fn (): PrivacyContext => $this->context(1),
            ClinicalRecord::class,
            'patient',
        );
    }

    // ----- Q&A ---------------------------------------------------------------

    public function test_the_qa_domain_costs_the_same_for_ten_and_a_hundred_rows(): void
    {
        $this->assertRowCountIndependent(
            fn (int $n) => $this->seedQa($n),
            fn (): PrivacyContext => $this->context(
                1,
                ['is_moderator' => false, 'eligible_organization_ids' => [10]],
                ['workspace_id' => 1],
            ),
            Question::class,
            'collaborators',
        );
    }

    // ----- finance -----------------------------------------------------------

    public function test_the_finance_domain_costs_the_same_for_ten_and_a_hundred_rows(): void
    {
        $this->assertRowCountIndependent(
            fn (int $n) => $this->seedFinance($n),
            fn (): PrivacyContext => $this->context(1),
            FeeSchedule::class,
            'account',
        );
    }

    // ----- lazy loading is the only per-row cost ----------------------------

    public function test_lazy_loading_costs_per_row_only_because_the_caller_asked_for_it(): void
    {
        $this->seedClinical(self::SMALL);

        $context = $this->context(1);

        [$eager, $eagerQueries] = $this->countingQueries(
            fn () => ClinicalRecord::privacyQuery($context)->with(['patient'])->get(),
        );

        $this->assertCount(self::SMALL, $eager);
        $this->assertCount(2, $eagerQueries, 'with() must cost one query per relation, not per row');

        [$rows, $lazyQueries] = $this->countingQueries(static function () use ($context): int {
            $seen = 0;

            foreach (ClinicalRecord::privacyQuery($context)->get() as $record) {
                $seen += $record->patient === null ? 0 : 1;
            }

            return $seen;
        });

        $this->assertSame(self::SMALL, $rows);
        $this->assertCount(1 + self::SMALL, $lazyQueries, 'one for the collection, then one per row the caller touched');
    }

    public function test_eager_loading_stays_flat_as_the_row_count_grows(): void
    {
        foreach ([self::SMALL, self::LARGE] as $n) {
            $this->dropFixtureTables();
            $this->seedClinical($n);

            [$models, $queries] = $this->countingQueries(
                fn () => ClinicalRecord::privacyQuery($this->context(1))->with(['patient'])->get(),
            );

            $this->assertCount($n, $models);
            $this->assertCount(2, $queries, sprintf('with() issued %d queries for %d rows', count($queries), $n));
        }
    }

    public function test_aggregates_are_single_queries_whatever_the_row_count(): void
    {
        foreach ([self::SMALL, self::LARGE] as $n) {
            $this->dropFixtureTables();
            $this->seedFinance($n);

            $context = $this->context(1);

            foreach ([
                'count' => fn () => FeeSchedule::privacyQuery($context)->count(),
                'exists' => fn () => FeeSchedule::privacyQuery($context)->exists(),
                'sum' => fn () => FeeSchedule::privacyQuery($context)->sum('amount'),
            ] as $label => $call) {
                [, $queries] = $this->countingQueries($call);

                $this->assertCount(1, $queries, sprintf('%s at %d rows', $label, $n));
            }

            [, $paginateQueries] = $this->countingQueries(
                fn () => FeeSchedule::privacyQuery($context)->paginate(5)->total(),
            );

            $this->assertCount(2, $paginateQueries, 'paginate() is one count plus one page');
        }
    }

    // ----- helpers -----------------------------------------------------------

    /**
     * @param callable(int): void $seed
     * @param callable(): PrivacyContext $context
     * @param class-string<Model> $model
     */
    private function assertRowCountIndependent(callable $seed, callable $context, string $model, string $relation): void
    {
        $measurements = [];

        foreach ([self::SMALL, self::LARGE] as $n) {
            $this->dropFixtureTables();
            $seed($n);

            $measurements[$n] = $this->measure($model, $context(), $relation, $n);
        }

        $this->assertSame(
            $measurements[self::SMALL],
            $measurements[self::LARGE],
            sprintf('%s: the query count followed the row count', $model),
        );

        foreach ($measurements[self::SMALL] as $label => $count) {
            $this->assertLessThan(self::SMALL, $count, sprintf('%s: %s should be a bounded number of queries', $model, $label));
        }
    }

    /**
     * @param class-string<Model> $model
     * @return array<string, int>
     */
    private function measure(string $model, PrivacyContext $context, string $relation, int $expectedRows): array
    {
        [$models, $getQueries] = $this->countingQueries(
            static fn () => Privacy::query($model, $context)->get(),
        );

        $this->assertCount($expectedRows, $models, $model.' did not return the seeded rows');

        [, $withQueries] = $this->countingQueries(
            static fn () => Privacy::query($model, $context)->with([$relation])->get(),
        );

        [, $runtimeQueries] = $this->countingQueries(function () use ($model, $context): int {
            $prototype = new $model();
            $resolved = (new PolicyResolver())->resolveRead($model, $context);

            $rows = [];

            foreach ($model::query()->orderBy($prototype->getKeyName())->get() as $row) {
                $rows[] = RowSnapshot::fromModel($row);
            }

            $facts = (new RelationFactLoader(DB::connection()))->prepare($resolved->toPredicate(), $rows);
            $evaluator = new PredicateEvaluator();
            $reducer = new StageReducer();
            $allowed = 0;

            foreach ($rows as $row) {
                $allowed += $resolved->decide($row, $facts, $evaluator, $reducer)->value === 'allow' ? 1 : 0;
            }

            return $allowed;
        });

        return [
            'protected get()' => count($getQueries),
            'protected get() with eager load' => count($withQueries),
            // Minus the one query that fetched the rows to snapshot.
            'runtime prepare + evaluate' => count($runtimeQueries) - 1,
        ];
    }

    private function seedClinical(int $n): void
    {
        $this->createClinicalSchema();

        $patients = [];
        $grants = [];
        $records = [];

        for ($i = 1; $i <= $n; $i++) {
            $patients[] = ['id' => $i, 'owner_id' => $i % 2 === 0 ? 1 : 2, 'name' => "P$i", 'deleted_at' => null];
            $grants[] = ['id' => $i, 'patient_id' => $i, 'user_id' => 1, 'level' => 'reader', 'revoked_at' => null, 'expires_at' => null];
            $records[] = ['id' => $i, 'patient_id' => $i, 'review_status' => 'open', 'body' => "r$i", 'deleted_at' => null];
        }

        DB::table('fx_patients')->insert($patients);
        DB::table('fx_patient_grants')->insert($grants);
        DB::table('fx_clinical_records')->insert($records);
    }

    private function seedQa(int $n): void
    {
        $this->createQaSchema();

        DB::table('fx_workspaces')->insert([['id' => 1, 'name' => 'W1']]);

        $questions = [];
        $collaborators = [];

        for ($i = 1; $i <= $n; $i++) {
            $questions[] = [
                'id' => $i, 'workspace_id' => 1, 'author_id' => $i % 3 === 0 ? 1 : 2,
                'organization_id' => $i % 3 === 1 ? 10 : null, 'is_faq' => $i % 3 === 2, 'archived' => false,
                'title' => "q$i",
            ];
            $collaborators[] = ['id' => $i, 'question_id' => $i, 'user_id' => 1];
        }

        DB::table('fx_questions')->insert($questions);
        DB::table('fx_question_collaborators')->insert($collaborators);
    }

    private function seedFinance(int $n): void
    {
        $this->createFinanceSchema();

        $accounts = [];
        $fees = [];

        for ($i = 1; $i <= $n; $i++) {
            $accounts[] = ['acct_id' => $i, 'acct_owner' => $i % 2 === 0 ? 1 : 5, 'label' => "A$i", 'legacy_parent_id' => null];
            $fees[] = ['id' => $i, 'acct_id' => $i, 'amount' => '1.25', 'legacy_ref' => null, 'label' => "f$i"];
        }

        DB::table('fx_accounts')->insert($accounts);
        DB::table('fx_account_grants')->insert([
            ['id' => 1, 'owner_id' => 5, 'grantee_id' => 1, 'access' => 'viewer', 'revoked_at' => null],
        ]);
        DB::table('fx_fee_schedules')->insert($fees);
    }
}

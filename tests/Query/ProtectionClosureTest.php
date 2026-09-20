<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Query;

use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Query\ProtectedCollection;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\Patient;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd\AutoloadingRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd\CountingPatient;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Protection is closed under reachability: every model and every collection
 * reachable from a protected result — by an eager load the caller asked for, by
 * one the model declares itself, by a lazy load, or by copying — is protected
 * too, and nothing reachable was loaded around a policy.
 */
final class ProtectionClosureTest extends FixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createClinicalSchema();
        ClinicalSeed::seed();
    }

    public function test_a_models_own_eager_loads_do_not_go_around_the_related_policy(): void
    {
        // Viewer 1 may not see patient 2, the parent of record 4.
        $record = AutoloadingRecord::privacyQuery($this->context(1))->findOrFail(4);

        $this->assertNull($record->patient, 'the model-level $with must not load the parent unfiltered');

        $own = AutoloadingRecord::privacyQuery($this->context(1))->findOrFail(1);

        $this->assertSame(1, $own->patient?->getKey());
        $this->assertTrue($own->patient->isPrivacyProtected());
    }

    public function test_a_related_models_own_eager_loads_are_not_applied_either(): void
    {
        $patient = Patient::privacyQuery($this->context(1))->findOrFail(1);

        Privacy::register(ClinicalRecord::class, ModelPolicy::for(ClinicalRecord::class)->read(
            RuleSet::define()->terminal(Decision::Allow),
        ));

        // Eagerly and lazily reached records come from a query without model-level eager loads.
        foreach (AutoloadingRecord::privacyQuery($this->context(1))->with(['patient'])->get() as $record) {
            $this->assertEverythingReachableIsProtected($record);
        }

        $this->assertEverythingReachableIsProtected($patient);
    }

    public function test_a_model_level_aggregate_is_refused(): void
    {
        $this->expectException(UnsupportedProtectedOperation::class);

        CountingPatient::privacyQuery($this->context(1))->get();
    }

    public function test_an_eager_loaded_has_many_is_a_protected_collection(): void
    {
        $patient = Patient::privacyQuery($this->context(1))->with(['records'])->findOrFail(1);

        $this->assertInstanceOf(ProtectedCollection::class, $patient->records);
        $this->assertEverythingReachableIsProtected($patient);

        foreach (['load' => ['patient'], 'loadMissing' => ['patient'], 'loadCount' => ['patient'], 'fresh' => [], 'toQuery' => []] as $method => $arguments) {
            try {
                $patient->records->{$method}(...$arguments);
                $this->fail("Collection::{$method}() was open on an eager-loaded relation.");
            } catch (UnsupportedProtectedOperation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_eager_belongs_to_survives_a_select_that_omits_the_foreign_key(): void
    {
        $records = ClinicalRecord::privacyQuery($this->context(1))
            ->select(['review_status'])
            ->with(['patient'])
            ->orderBy('id')
            ->get();

        $this->assertSame([1, 3, 10, 11], $records->modelKeys());

        foreach ($records as $record) {
            $this->assertNotNull($record->patient, 'a visible parent must not read as null because its key was not selected');
        }
    }

    public function test_an_eager_has_many_survives_a_select_that_omits_nothing_it_needs(): void
    {
        $patient = Patient::privacyQuery($this->context(1))->select(['owner_id'])->with(['records'])->findOrFail(1);

        $this->assertSame([1, 10, 11], $patient->records->modelKeys());
    }

    public function test_a_copy_of_a_protected_model_is_refused(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);

        $this->expectException(UnsupportedProtectedOperation::class);

        $record->replicate();
    }

    public function test_an_ordinary_model_still_replicates(): void
    {
        $copy = ClinicalRecord::query()->findOrFail(1)->replicate();

        $this->assertFalse($copy->exists);
        $this->assertSame(1, $copy->patient?->getKey());
    }

    private function assertEverythingReachableIsProtected(Model $model, int $depth = 0): void
    {
        $this->assertTrue(method_exists($model, 'isPrivacyProtected') && $model->isPrivacyProtected(), $model::class.' is reachable but not protected');

        foreach ($model->getRelations() as $name => $related) {
            if ($related instanceof EloquentCollection) {
                $this->assertInstanceOf(ProtectedCollection::class, $related, "relation {$name} is a plain collection");
            }

            foreach ($related instanceof Model ? [$related] : ($related ?? []) as $one) {
                if ($one instanceof Model && $depth < 4) {
                    $this->assertEverythingReachableIsProtected($one, $depth + 1);
                }
            }
        }
    }
}

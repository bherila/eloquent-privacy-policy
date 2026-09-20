<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Query;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingAttribute;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Query\ProtectedBuilder;
use BWH\EloquentPrivacyPolicy\Query\ProtectedCollection;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\Patient;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\QaSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Question;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\QuestionCollaborator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Contract section 5.2: what a model hydrated by a protected builder may do. */
final class ReturnedModelTest extends FixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createClinicalSchema();
        $this->createQaSchema();
        ClinicalSeed::seed();
        QaSeed::seed();
    }

    // ----- relations go through the related policy --------------------------

    public function test_a_lazy_belongs_to_goes_through_the_related_policy_and_stays_protected(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);

        $patient = $record->patient;

        $this->assertInstanceOf(Patient::class, $patient);
        $this->assertSame(1, $patient->getKey());
        $this->assertTrue($patient->isPrivacyProtected());
        $this->assertSame($record->privacyContext(), $patient->privacyContext());
    }

    public function test_an_invisible_parent_is_null_and_not_the_row(): void
    {
        // A record policy that admits every row, so the only thing standing
        // between the viewer and the parent row is the parent's own policy.
        Privacy::register(ClinicalRecord::class, ModelPolicy::for(ClinicalRecord::class)->read(
            RuleSet::define()->terminal(Decision::Allow),
        ));

        $context = $this->context(1);

        $own = ClinicalRecord::privacyQuery($context)->findOrFail(1);   // patient 1: visible
        $other = ClinicalRecord::privacyQuery($context)->findOrFail(4); // patient 2: not visible
        $dangling = ClinicalRecord::privacyQuery($context)->findOrFail(9);
        $orphan = ClinicalRecord::privacyQuery($context)->findOrFail(8);

        $this->assertSame(1, $own->patient?->getKey());
        $this->assertNull($other->patient, 'an invisible parent is null, never the row');
        $this->assertNull($dangling->patient);
        $this->assertNull($orphan->patient);

        // And nothing of the hidden row leaks through the relation cache.
        $this->assertTrue($other->relationLoaded('patient'));
        $this->assertNull($other->getRelation('patient'));
        $this->assertArrayNotHasKey('patient', array_filter($other->relationsToArray()));
    }

    public function test_a_lazy_has_many_is_filtered_and_recursively_protected(): void
    {
        $patient = Patient::privacyQuery($this->context(1))->findOrFail(1);

        $records = $patient->records;

        $this->assertInstanceOf(ProtectedCollection::class, $records);
        $this->assertSame([1, 10, 11], $records->modelKeys(), 'embargoed and soft-deleted rows stay hidden');

        foreach ($records as $record) {
            $this->assertTrue($record->isPrivacyProtected());
            $this->assertInstanceOf(Patient::class, $record->patient);
            $this->assertTrue($record->patient->isPrivacyProtected());
        }
    }

    public function test_privacy_relation_returns_a_protected_builder_rooted_at_the_related_model(): void
    {
        $patient = Patient::privacyQuery($this->context(1))->findOrFail(1);

        $query = $patient->privacyRelation('records');

        $this->assertInstanceOf(ProtectedBuilder::class, $query);
        $this->assertSame([1], $query->where('review_status', 'open')->orderBy('id')->get()->modelKeys());
    }

    public function test_privacy_relation_needs_the_key_column_to_have_been_selected(): void
    {
        $question = Question::privacyQuery($this->qa(1))->select(['title'])->findOrFail(1);

        $this->assertSame(1, $question->getKey());

        // The HasMany's local key is the primary key, which select() keeps.
        $this->assertSame([], $question->privacyRelation('collaborators')->get()->modelKeys());

        $record = ClinicalRecord::privacyQuery($this->context(1))->select(['review_status'])->findOrFail(1);

        $this->expectException(MissingAttribute::class);
        $record->privacyRelation('patient');
    }

    public function test_eager_loading_filters_related_rows_and_marks_them_protected(): void
    {
        $questions = Question::privacyQuery($this->qa(1))->with(['collaborators'])->orderBy('id')->get();

        $byId = [];

        foreach ($questions as $question) {
            $byId[$question->getKey()] = $question->getRelation('collaborators')->modelKeys();

            foreach ($question->getRelation('collaborators') as $collaborator) {
                $this->assertTrue($collaborator->isPrivacyProtected());
            }
        }

        // Collaborator 2 names viewer 2, so viewer 1 must not see it on q8.
        $this->assertSame([1], $byId[3]);
        $this->assertSame([], $byId[1]);
    }

    public function test_eager_loading_a_belongs_to_yields_null_for_an_invisible_parent(): void
    {
        $records = ClinicalRecord::privacyQuery($this->context(2))->with(['patient'])->orderBy('id')->get();

        $parents = [];

        foreach ($records as $record) {
            $parents[$record->getKey()] = $record->getRelation('patient')?->getKey();
        }

        $this->assertSame([3 => 3, 4 => 2, 5 => 4], $parents);

        foreach ($records as $record) {
            $this->assertTrue($record->getRelation('patient')?->isPrivacyProtected() ?? false);
        }
    }

    public function test_an_appended_accessor_that_touches_a_relation_stays_protected(): void
    {
        $question = Question::privacyQuery($this->qa(1))->findOrFail(3);

        $array = $question->toArray();

        $this->assertArrayHasKey('collaborator_count', $array);
        $this->assertSame(1, $array['collaborator_count'] ?? null, 'only the collaborator naming viewer 1 is counted');
        $this->assertTrue($question->getRelation('collaborators')->first()?->isPrivacyProtected() ?? false);
        $this->assertJson($question->toJson());

        // Viewer 2's own read of the same question is not affected.
        $other = Question::privacyQuery($this->qa(2))->findOrFail(3);
        $this->assertSame(0, $other->toArray()['collaborator_count'] ?? null);
    }

    // ----- every rejected path, and nothing changes in the database ---------

    public function test_every_rejected_operation_throws_and_changes_nothing(): void
    {
        $before = $this->snapshotOfEverything();

        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);
        $patient = Patient::privacyQuery($this->context(1))->findOrFail(1);
        $question = Question::privacyQuery($this->qa(1))->findOrFail(1);
        $collection = Patient::privacyQuery($this->context(1))->get();

        $rejected = [
            // relation method calls hand out raw builders
            'relation method' => fn () => $record->patient(),
            'has-many method' => fn () => $patient->records(),
            // unsupported relation shapes
            'hasOne' => fn () => $question->firstCollaborator,
            'belongsToMany' => fn () => $question->tagged,
            'morphTo' => fn () => $question->owner,
            'morphTo method' => fn () => $question->owner(),
            'constrained relation' => fn () => $question->namedCollaborators,
            'related model without a policy' => fn () => $question->auditNotes,
            'constrained via privacyRelation' => fn () => $question->privacyRelation('namedCollaborators'),
            'unsupported via with()' => fn () => Question::privacyQuery($this->qa(1))->with(['tagged']),
            'policyless via with()' => fn () => Question::privacyQuery($this->qa(1))->with(['auditNotes']),
            // reloads
            'refresh' => fn () => $record->refresh(),
            'fresh' => fn () => $record->fresh(),
            'load' => fn () => $record->load('patient'),
            'loadMissing' => fn () => $record->loadMissing('patient'),
            'loadCount' => fn () => $patient->loadCount('records'),
            'loadSum' => fn () => $patient->loadSum('records', 'id'),
            // writes
            'save' => fn () => $record->save(),
            'saveQuietly' => fn () => $record->saveQuietly(),
            'update' => fn () => $record->update(['review_status' => 'tampered']),
            'updateQuietly' => fn () => $record->updateQuietly(['review_status' => 'tampered']),
            'push' => fn () => $record->push(),
            'delete' => fn () => $record->delete(),
            'deleteQuietly' => fn () => $record->deleteQuietly(),
            'forceDelete' => fn () => $patient->forceDelete(),
            'restore' => fn () => $patient->restore(),
            'increment' => fn () => $record->increment('patient_id'),
            'decrement' => fn () => $record->decrement('patient_id'),
            'incrementEach' => fn () => $record->incrementEach(['patient_id' => 1]),
            'decrementEach' => fn () => $record->decrementEach(['patient_id' => 1]),
            // collection-level loading
            'collection load' => fn () => $collection->load('records'),
            'collection loadMissing' => fn () => $collection->loadMissing('records'),
            'collection loadCount' => fn () => $collection->loadCount('records'),
            'collection loadExists' => fn () => $collection->loadExists('records'),
            'collection fresh' => fn () => $collection->fresh(),
            'collection toQuery' => fn () => $collection->toQuery(),
            'collection autoloading' => fn () => $collection->withRelationshipAutoloading(),
        ];

        foreach ($rejected as $label => $call) {
            try {
                $call();
                $this->fail(sprintf('"%s" was not rejected.', $label));
            } catch (UnsupportedProtectedOperation) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame($before, $this->snapshotOfEverything(), sprintf('"%s" changed the database', $label));
        }
    }

    /**
     * Section 5.2 lists touch() among the rejected writes. The guards catch it
     * through save(), but Model::touch() returns false without reaching save()
     * on a model that does not use timestamps, so the call is silently ignored
     * rather than refused. Nothing is written either way.
     */
    public function test_touch_is_rejected_even_when_the_model_has_no_timestamps(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);

        $this->assertFalse($record->usesTimestamps(), 'the fixture deliberately has $timestamps = false');

        $this->expectException(UnsupportedProtectedOperation::class);
        $record->touch();
    }

    public function test_touch_with_an_attribute_is_rejected(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);

        $this->expectException(UnsupportedProtectedOperation::class);
        $record->touch('review_status');
    }

    public function test_the_relationship_autoloading_switch_creates_no_bypass(): void
    {
        $before = $this->snapshotOfEverything();

        Model::automaticallyEagerLoadRelationships(true);

        try {
            $questions = Question::privacyQuery($this->qa(1))->orderBy('id')->get();
            $question = $questions->firstOrFail();

            $this->assertTrue($question->isPrivacyProtected());
            $this->assertSame([], $question->collaborators->modelKeys(), 'q1 has no collaborator naming viewer 1');

            $withCollaborator = $questions->firstWhere('id', 3);
            $this->assertSame([1], $withCollaborator?->collaborators->modelKeys());

            foreach ([
                fn () => $questions->withRelationshipAutoloading(),
                fn () => $questions->load('collaborators'),
            ] as $call) {
                try {
                    $call();
                    $this->fail('Autoloading opened a collection-level load.');
                } catch (UnsupportedProtectedOperation) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            Model::automaticallyEagerLoadRelationships(false);
        }

        $this->assertSame($before, $this->snapshotOfEverything());
        $this->assertFalse(Model::isAutomaticallyEagerLoadingRelationships());
    }

    public function test_ordinary_eloquent_usage_of_the_same_classes_is_unchanged(): void
    {
        $before = $this->snapshotOfEverything();

        $record = ClinicalRecord::query()->findOrFail(4);

        $this->assertFalse($record->isPrivacyProtected());
        $this->assertSame(2, $record->patient?->getKey(), 'ordinary relations are not filtered');
        $this->assertSame(10, ClinicalRecord::query()->count(), 'no global privacy scope was added');

        $record->refresh();
        $record->load('patient');
        $record->setAttribute('review_status', 'open');
        $this->assertTrue($record->save());
        $this->assertNotNull($record->fresh());

        $patient = Patient::withTrashed()->findOrFail(6);
        $this->assertTrue($patient->trashed());
        $this->assertTrue((bool) $patient->restore());
        $this->assertNotSame($before, $this->snapshotOfEverything(), 'ordinary writes really do write');

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $record->patient());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, ClinicalRecord::query()->get());
        $this->assertNotInstanceOf(ProtectedCollection::class, ClinicalRecord::query()->get());
    }

    public function test_a_protected_instance_cannot_be_rebound_to_another_context(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);

        // Re-binding the identical context is the idempotent case the builder
        // itself relies on when one related row is shared by several parents.
        $context = $record->privacyContext();
        $this->assertNotNull($context);
        $this->assertSame($record, $record->bindPrivacyContext($context));

        $this->expectException(UnsupportedProtectedOperation::class);
        $record->bindPrivacyContext($this->context(2));
    }

    public function test_privacy_relation_is_not_available_on_an_unprotected_instance(): void
    {
        $this->expectException(UnsupportedProtectedOperation::class);

        ClinicalRecord::query()->findOrFail(1)->privacyRelation('patient');
    }

    public function test_a_relation_name_that_is_not_a_relation_is_rejected(): void
    {
        $record = ClinicalRecord::privacyQuery($this->context(1))->findOrFail(1);

        $this->expectException(UnsupportedProtectedOperation::class);
        $record->privacyRelation('review_status');
    }

    // ----- helpers -----------------------------------------------------------

    /** Every fixture row, so any write at all shows up as a difference. */
    private function snapshotOfEverything(): string
    {
        $state = [];

        foreach (['fx_patients', 'fx_patient_grants', 'fx_clinical_records', 'fx_questions', 'fx_question_collaborators', 'fx_workspaces', 'fx_audit_notes'] as $table) {
            $rows = [];

            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $rows[] = (array) $row;
            }

            $state[$table] = $rows;
        }

        return (string) json_encode($state);
    }

    private function qa(int $viewer): PrivacyContext
    {
        return $this->context(
            $viewer,
            ['is_moderator' => false, 'eligible_organization_ids' => [10]],
            ['workspace_id' => 1],
        );
    }
}

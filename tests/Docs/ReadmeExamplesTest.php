<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs;

use BWH\EloquentPrivacyPolicy\Action\ActionExecutor;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\Operation;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Context\ResourceScope;
use BWH\EloquentPrivacyPolicy\Context\Viewer;
use BWH\EloquentPrivacyPolicy\Exceptions\ActionDenied;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Query\FilterGroup;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical\ClinicalDeleteAction;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical\ClinicalWriteAction;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical\Patient;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Finance\LedgerAccount;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Finance\LedgerEntry;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Finance\LedgerEntryMoveAction;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Qa\Question;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Qa\QuestionEditAction;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\QuickStart\Project;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\QuickStart\Record;
use BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\QuickStart\RecordUpdateAction;
use BWH\EloquentPrivacyPolicy\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every example in README.md, run for real against sqlite. If the README
 * shows code, this file is where it is proven; if a test here does not exist,
 * the README does not claim the corresponding behaviour.
 */
final class ReadmeExamplesTest extends TestCase
{
    private const string NOW = '2026-06-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Privacy::flush();

        foreach ([
            'doc_record_grants', 'doc_record_shares', 'doc_records', 'doc_projects',
            'doc_patient_grants', 'doc_clinical_records', 'doc_patients',
            'doc_question_collaborators', 'doc_questions',
            'doc_ledger_entries', 'doc_ledger_accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createQuickStartSchema();
        $this->createClinicalSchema();
        $this->createQaSchema();
        $this->createFinanceSchema();
    }

    // ----------------------------------------------------------------------
    // Quick start
    // ----------------------------------------------------------------------

    public function test_quick_start_visibility(): void
    {
        $this->seedQuickStart();

        $ids = Record::privacyQuery($this->quickStartContext(1))->orderBy('id')->get()->modelKeys();

        $this->assertSame([1, 2, 4], $ids);
    }

    public function test_quick_start_a_caller_or_cannot_widen_the_result(): void
    {
        $this->seedQuickStart();

        $ids = Record::privacyQuery($this->quickStartContext(1))
            ->where('title', 'Alpha')
            ->orWhere('id', '>', 0)
            ->orWhere(fn (FilterGroup $group) => $group->where('tenant_id', 8)->orWhereNull('tenant_id'))
            ->orderBy('id')
            ->get()
            ->modelKeys();

        // Every orWhere is confined to the caller's own nested group: it can
        // narrow within what privacy already allows, never add rows to it.
        $this->assertSame([1, 2, 4], $ids);
    }

    public function test_quick_start_relations(): void
    {
        $this->seedQuickStart();
        $context = $this->quickStartContext(1);

        // Record 4 lives in project 1, which viewer 1 owns: with() eager-loads it.
        $viaEager = Record::privacyQuery($context)->with(['project'])->findOrFail(4);
        $this->assertSame(1, $viaEager->project?->getKey());
        $this->assertTrue($viaEager->project->isPrivacyProtected());

        // Lazy load, same record: the relation goes through Project's own policy.
        $viaLazy = Record::privacyQuery($context)->findOrFail(4);
        $this->assertSame(1, $viaLazy->project?->getKey());

        // Record 1 is visible to viewer 1 (author), but it lives in project 2,
        // which viewer 1 does not own: the lazy load comes back empty rather
        // than disclosing the project.
        $authored = Record::privacyQuery($context)->findOrFail(1);
        $this->assertNull($authored->project);

        // The escapes the contract says are rejected.
        foreach ([
            fn () => $viaLazy->project(),
            fn () => $viaLazy->refresh(),
            fn () => $viaLazy->save(),
            fn () => $viaLazy->delete(),
        ] as $escape) {
            try {
                $escape();
                $this->fail('An escape route was not rejected.');
            } catch (UnsupportedProtectedOperation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_quick_start_action_end_to_end(): void
    {
        $this->seedQuickStart();

        // Record 4 lives in project 1, where viewer 1 holds a "write" grant.
        $result = (new ActionExecutor())->execute(
            new RecordUpdateAction(recordId: 4, projectId: 1, title: 'Delta (renamed)'),
            $this->quickStartContext(1, operation: 'record.write'),
        );

        $this->assertSame('record.update', $result->receipt->action);
        $this->assertSame(Record::class, $result->receipt->model);
        $this->assertSame(4, $result->receipt->targetKey);
        $this->assertSame(1, $result->receipt->version);

        $readable = $result->readable();
        $this->assertNotNull($readable);
        $this->assertSame('Delta (renamed)', $readable->title);

        // The grant-writer side: revoke inside Privacy::withAnchors(), taking
        // the same anchor the executor takes.
        Privacy::withAnchors(
            [new Anchor('doc_projects', 1)],
            static fn (): int => DB::table('doc_record_grants')->where('project_id', '=', 1)->delete(),
        );

        $this->expectException(ActionDenied::class);

        (new ActionExecutor())->execute(
            new RecordUpdateAction(recordId: 4, projectId: 1, title: 'Delta (again)'),
            $this->quickStartContext(1, operation: 'record.write'),
        );
    }

    // ----------------------------------------------------------------------
    // Domain example (a): patient-owned clinical records
    // ----------------------------------------------------------------------

    public function test_clinical_example(): void
    {
        DB::table('doc_patients')->insert([
            ['id' => 1, 'user_id' => 10, 'name' => 'Patient A'],
            ['id' => 2, 'user_id' => 11, 'name' => 'Patient B'],
        ]);

        DB::table('doc_patient_grants')->insert([
            ['id' => 1, 'patient_id' => 1, 'user_id' => 20, 'ability' => 'write', 'expires_at' => null],
            ['id' => 2, 'patient_id' => 1, 'user_id' => 21, 'ability' => 'view', 'expires_at' => null],
        ]);

        DB::table('doc_clinical_records')->insert([
            ['id' => 1, 'patient_id' => 1, 'note' => 'first note'],
        ]);

        // Read: the patient, an active write-grantee, and an active view-grantee
        // all see the record; an unrelated viewer sees nothing.
        $this->assertSame([1], ClinicalRecord::privacyQuery($this->identified(10))->get()->modelKeys());
        $this->assertSame([1], ClinicalRecord::privacyQuery($this->identified(20))->get()->modelKeys());
        $this->assertSame([1], ClinicalRecord::privacyQuery($this->identified(21))->get()->modelKeys());
        $this->assertSame([], ClinicalRecord::privacyQuery($this->identified(99))->get()->modelKeys());

        // A write grant is enough to update ...
        (new ActionExecutor())->execute(new ClinicalWriteAction(1, 1, 'updated by caregiver'), $this->identified(20));
        $this->assertSame('updated by caregiver', DB::table('doc_clinical_records')->where('id', 1)->value('note'));

        // ... but not to delete, and a view-only grant cannot even update.
        try {
            (new ActionExecutor())->execute(new ClinicalWriteAction(1, 1, 'blocked'), $this->identified(21));
            $this->fail('A view-only grant authorised a write.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        try {
            (new ActionExecutor())->execute(new ClinicalDeleteAction(1, 1), $this->identified(20));
            $this->fail('A write grant authorised a delete.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull(DB::table('doc_clinical_records')->find(1));

        // Only the patient can delete their own record.
        (new ActionExecutor())->execute(new ClinicalDeleteAction(1, 1), $this->identified(10));
        $this->assertNull(DB::table('doc_clinical_records')->find(1));
    }

    // ----------------------------------------------------------------------
    // Domain example (b): workspace Q&A
    // ----------------------------------------------------------------------

    public function test_workspace_qa_example(): void
    {
        DB::table('doc_questions')->insert([
            ['id' => 1, 'workspace_id' => 5, 'author_id' => 1, 'is_faq' => false, 'org_id' => null, 'archived' => false, 'title' => 'own question'],
            ['id' => 2, 'workspace_id' => 5, 'author_id' => 2, 'is_faq' => true, 'org_id' => null, 'archived' => false, 'title' => 'an FAQ'],
            ['id' => 3, 'workspace_id' => 5, 'author_id' => 2, 'is_faq' => false, 'org_id' => null, 'archived' => false, 'title' => 'tagged'],
            ['id' => 4, 'workspace_id' => 5, 'author_id' => 2, 'is_faq' => false, 'org_id' => 100, 'archived' => false, 'title' => 'org question'],
            ['id' => 5, 'workspace_id' => 5, 'author_id' => 2, 'is_faq' => true, 'org_id' => null, 'archived' => true, 'title' => 'archived FAQ'],
            ['id' => 6, 'workspace_id' => 6, 'author_id' => 1, 'is_faq' => false, 'org_id' => null, 'archived' => false, 'title' => 'other workspace'],
        ]);

        DB::table('doc_question_collaborators')->insert([
            ['id' => 1, 'question_id' => 3, 'user_id' => 1],
        ]);

        $context = new PrivacyContext(
            Viewer::identified(1),
            new Operation('question.list'),
            new ResourceScope(['workspace_id' => 5]),
            facts: new Facts(['member_org_ids' => [100]]),
        );

        // Own question, the FAQ, the tagged thread, and the org thread are
        // visible; the archived FAQ (deny beats a grant) and the other
        // workspace's question (mandatory boundary) are not.
        $this->assertSame([1, 2, 3, 4], Question::privacyQuery($context)->orderBy('id')->get()->modelKeys());

        // Seeing a thread does not permit editing it: the collaborator on
        // question 3 may read it but may not edit it; its author may.
        try {
            (new ActionExecutor())->execute(new QuestionEditAction(3, 'edited by collaborator'), $this->questionContext(1));
            $this->fail('A collaborator edited a question they did not author.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        (new ActionExecutor())->execute(new QuestionEditAction(3, 'edited by author'), $this->questionContext(2));
        $this->assertSame('edited by author', DB::table('doc_questions')->where('id', 3)->value('title'));
    }

    // ----------------------------------------------------------------------
    // Domain example (c): parent-owned finance records
    // ----------------------------------------------------------------------

    public function test_finance_example(): void
    {
        DB::table('doc_ledger_accounts')->insert([
            ['account_no' => 1, 'holder_id' => 1, 'name' => 'Checking'],
            ['account_no' => 2, 'holder_id' => 2, 'name' => "Someone else's"],
            ['account_no' => 3, 'holder_id' => 1, 'name' => 'Savings'],
        ]);

        DB::table('doc_ledger_entries')->insert([
            ['id' => 1, 'account_no' => 1, 'amount' => 100, 'description' => 'e1'],
        ]);

        // The entry has no owner column of its own; visibility comes only
        // from the account it belongs to.
        $this->assertSame([1], LedgerEntry::privacyQuery($this->identified(1))->get()->modelKeys());
        $this->assertSame([], LedgerEntry::privacyQuery($this->identified(2))->get()->modelKeys());

        // Reparenting to an account the same viewer also holds is authorised ...
        (new ActionExecutor())->execute(new LedgerEntryMoveAction(1, 1, 3), $this->identified(1));
        $this->assertSame(3, DB::table('doc_ledger_entries')->where('id', 1)->value('account_no'));

        DB::table('doc_ledger_entries')->where('id', 1)->update(['account_no' => 1]);

        // ... but not to an account someone else holds: owning the account an
        // entry is leaving says nothing about the one it would join.
        try {
            (new ActionExecutor())->execute(new LedgerEntryMoveAction(1, 1, 2), $this->identified(1));
            $this->fail('An entry was moved into an account the viewer does not hold.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, DB::table('doc_ledger_entries')->where('id', 1)->value('account_no'));
    }

    // ----------------------------------------------------------------------
    // Schema / seed helpers
    // ----------------------------------------------------------------------

    private function createQuickStartSchema(): void
    {
        Schema::create('doc_projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
        });

        Schema::create('doc_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('project_id')->nullable()->constrained('doc_projects');
            $table->unsignedBigInteger('author_id');
            $table->boolean('locked')->default(false);
            $table->string('title');
            $table->integer('revision')->default(0);
        });

        Schema::create('doc_record_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('doc_records');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('doc_record_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->string('ability');
        });
    }

    private function seedQuickStart(): void
    {
        DB::table('doc_projects')->insert([
            ['id' => 1, 'owner_id' => 1],
            ['id' => 2, 'owner_id' => 2],
        ]);

        DB::table('doc_records')->insert([
            // project 2 belongs to viewer 2, not viewer 1: the grants below
            // are what make records 1 and 2 visible to viewer 1, not the project.
            ['id' => 1, 'tenant_id' => 7, 'project_id' => 2, 'author_id' => 1, 'locked' => false, 'title' => 'Alpha'],
            ['id' => 2, 'tenant_id' => 7, 'project_id' => 2, 'author_id' => 2, 'locked' => false, 'title' => 'Beta'],
            ['id' => 3, 'tenant_id' => 7, 'project_id' => 2, 'author_id' => 2, 'locked' => false, 'title' => 'Gamma'],
            // project 1 belongs to viewer 1: visible via the project grant alone.
            ['id' => 4, 'tenant_id' => 7, 'project_id' => 1, 'author_id' => 2, 'locked' => false, 'title' => 'Delta'],
            ['id' => 5, 'tenant_id' => 7, 'project_id' => 2, 'author_id' => 2, 'locked' => false, 'title' => 'Epsilon'],
            ['id' => 6, 'tenant_id' => 7, 'project_id' => 2, 'author_id' => 1, 'locked' => true, 'title' => 'Zeta'],
            ['id' => 7, 'tenant_id' => 8, 'project_id' => 2, 'author_id' => 1, 'locked' => false, 'title' => 'Eta'],
        ]);

        DB::table('doc_record_shares')->insert([
            ['record_id' => 2, 'user_id' => 1, 'expires_at' => null],
            ['record_id' => 3, 'user_id' => 1, 'expires_at' => '2026-01-01 00:00:00'],
        ]);

        DB::table('doc_record_grants')->insert([
            ['project_id' => 1, 'user_id' => 1, 'ability' => 'write'],
        ]);
    }

    private function quickStartContext(int $viewer, string $operation = 'record.list'): PrivacyContext
    {
        return new PrivacyContext(
            Viewer::identified($viewer),
            new Operation($operation),
            new ResourceScope(['tenant_id' => 7]),
            facts: new Facts(['is_staff' => false]),
            now: new DateTimeImmutable(self::NOW),
        );
    }

    private function createClinicalSchema(): void
    {
        Schema::create('doc_patients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
        });

        Schema::create('doc_patient_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('doc_patients');
            $table->unsignedBigInteger('user_id');
            $table->string('ability');
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('doc_clinical_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('doc_patients');
            $table->text('note');
        });
    }

    private function identified(int $viewer): PrivacyContext
    {
        return new PrivacyContext(Viewer::identified($viewer), new Operation('record.list'));
    }

    private function questionContext(int $viewer): PrivacyContext
    {
        return new PrivacyContext(Viewer::identified($viewer), new Operation('question.write'));
    }

    private function createQaSchema(): void
    {
        Schema::create('doc_questions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('author_id');
            $table->boolean('is_faq')->default(false);
            $table->unsignedBigInteger('org_id')->nullable();
            $table->boolean('archived')->default(false);
            $table->string('title');
        });

        Schema::create('doc_question_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained('doc_questions');
            $table->unsignedBigInteger('user_id');
        });
    }

    private function createFinanceSchema(): void
    {
        Schema::create('doc_ledger_accounts', function (Blueprint $table): void {
            $table->increments('account_no');
            $table->unsignedBigInteger('holder_id');
            $table->string('name');
        });

        Schema::create('doc_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('account_no');
            $table->integer('amount');
            $table->string('description');
        });
    }
}

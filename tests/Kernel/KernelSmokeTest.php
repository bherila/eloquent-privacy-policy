<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Kernel;

use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\Operation;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Context\ResourceScope;
use BWH\EloquentPrivacyPolicy\Context\Viewer;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingAttribute;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingContext;
use BWH\EloquentPrivacyPolicy\Exceptions\StageEvaluationFailed;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Policy\PolicyResolver;
use BWH\EloquentPrivacyPolicy\Policy\StageReducer;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Query\FilterGroup;
use BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator;
use BWH\EloquentPrivacyPolicy\Runtime\RelationFactLoader;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;
use BWH\EloquentPrivacyPolicy\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The author's own end-to-end check of the kernel. The independent suites live
 * in tests/Policy and tests/Query.
 */
final class KernelSmokeTest extends TestCase
{
    private const string NOW = '2026-06-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Privacy::flush();

        Schema::dropIfExists('note_shares');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('folders');

        Schema::create('folders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreignId('folder_id')->nullable()->constrained('folders');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->boolean('locked')->nullable();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('note_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('note_id')->constrained('notes');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('expires_at')->nullable();
        });

        // Owner 1 has folders 1 (live) and 2 (soft-deleted); owner 2 has folder 3.
        Folder::query()->insert([
            ['id' => 1, 'owner_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'owner_id' => 1, 'deleted_at' => self::NOW],
            ['id' => 3, 'owner_id' => 2, 'deleted_at' => null],
        ]);

        $note = fn (int $id, ?int $tenant, ?int $folder, ?int $author, ?bool $locked) => [
            'id' => $id, 'tenant_id' => $tenant, 'folder_id' => $folder, 'author_id' => $author, 'locked' => $locked, 'title' => "n$id",
        ];

        Note::query()->insert([
            $note(1, 7, 3, 1, false),    // author
            $note(2, 7, 3, 2, false),    // shared, live
            $note(3, 7, 3, 2, false),    // shared, expired
            $note(4, 7, 1, 2, false),    // via own folder
            $note(5, 7, 2, 2, false),    // own folder but soft-deleted
            $note(6, 7, 3, 1, true),     // author but locked
            $note(7, 7, 3, 1, null),     // author, locked IS NULL -> NOT deny must hold
            $note(8, 8, 3, 1, false),    // other tenant
            $note(9, null, 3, 1, false), // tenant NULL
            $note(10, 7, null, 2, false), // no folder, stranger
        ]);

        DB::table('note_shares')->insert([
            ['note_id' => 2, 'user_id' => 1, 'expires_at' => null],
            ['note_id' => 3, 'user_id' => 1, 'expires_at' => '2026-01-01 00:00:00'],
            ['note_id' => 10, 'user_id' => 2, 'expires_at' => null],
        ]);
    }

    public function test_sql_and_runtime_agree_and_match_the_expected_set(): void
    {
        $this->assertParity($this->context(1), [1, 2, 4, 7]);
        $this->assertParity($this->context(1, staff: true), [1, 2, 3, 4, 5, 6, 7, 10]);
        // Viewer 2 authored 2-5 and 10, owns folder 3 (notes 1, 2, 3, 7 of this tenant, unlocked) and is shared 10.
        $this->assertParity($this->context(2), [1, 2, 3, 4, 5, 7, 10]);
        $this->assertParity(new PrivacyContext(Viewer::anonymous(), new Operation('note.list')), []);
    }

    public function test_a_caller_or_cannot_widen_the_result(): void
    {
        $ids = Note::privacyQuery($this->context(1))
            ->where('title', 'n1')
            ->orWhere('id', '>', 0)
            ->orWhere(fn (FilterGroup $group) => $group->where('tenant_id', 8)->orWhereNull('tenant_id'))
            ->orderBy('id')
            ->get()
            ->modelKeys();

        $this->assertSame([1, 2, 4, 7], $ids);
        $this->assertSame(4, Note::privacyQuery($this->context(1))->orWhere('id', '>', 0)->count());
        $this->assertNull(Note::privacyQuery($this->context(1))->find(8));
        $this->assertSame(1, Note::privacyQuery($this->context(1))->paginate(1, 2)->total() - 3);
    }

    public function test_missing_context_and_missing_facts_are_errors(): void
    {
        $this->expectException(MissingContext::class);

        Note::privacyQuery(null);
    }

    public function test_a_missing_fact_fails_the_stage_instead_of_skipping(): void
    {
        $context = new PrivacyContext(Viewer::identified(1), new Operation('note.list'), new ResourceScope(['tenant_id' => 7]));

        $this->expectException(StageEvaluationFailed::class);

        Note::privacyQuery($context)->get();
    }

    public function test_relations_stay_protected_and_escapes_are_rejected(): void
    {
        $note = Note::privacyQuery($this->context(1))->findOrFail(1);

        // Folder 3 belongs to someone else: the lazy load goes through its policy.
        $this->assertNull($note->folder);
        $this->assertSame(1, Note::privacyQuery($this->context(1))->with(['folder'])->findOrFail(4)->folder?->getKey());

        $folder = Folder::privacyQuery($this->context(1))->findOrFail(1);
        $this->assertSame([4], $folder->notes->modelKeys());
        $this->assertTrue($folder->notes->first()->isPrivacyProtected());

        foreach ([
            fn () => $note->folder(),
            fn () => $note->refresh(),
            fn () => $note->fresh(),
            fn () => $note->load('folder'),
            fn () => $note->update(['title' => 'x']),
            fn () => $note->saveQuietly(),
            fn () => $note->delete(),
            fn () => $note->increment('tenant_id'),
            fn () => $folder->forceDelete(),
            fn () => $folder->restore(),
            fn () => $folder->notes->load('folder'),
        ] as $escape) {
            try {
                $escape();
                $this->fail('An escape route was not rejected.');
            } catch (UnsupportedProtectedOperation) {
                $this->addToAssertionCount(1);
            }
        }

        // Ordinary, unmigrated usage is unchanged.
        $this->assertSame(3, Note::query()->findOrFail(1)->folder->getKey());
    }

    public function test_a_partial_snapshot_is_an_error_not_a_null(): void
    {
        $context = $this->context(1);
        $resolved = (new PolicyResolver())->resolveRead(Note::class, $context);
        $row = new RowSnapshot(['id' => 1, 'tenant_id' => 7, 'author_id' => 1]);
        $facts = (new RelationFactLoader(DB::connection()))->prepare($resolved->toPredicate(), []);

        try {
            $resolved->decide($row, $facts, new PredicateEvaluator(), new StageReducer());
            $this->fail('A missing column was treated as a value.');
        } catch (StageEvaluationFailed $failure) {
            $this->assertInstanceOf(MissingAttribute::class, $failure->getPrevious());
        }
    }

    /** @param list<int> $expected */
    private function assertParity(PrivacyContext $context, array $expected): void
    {
        $sql = Note::privacyQuery($context)->orderBy('id')->get()->modelKeys();

        $resolved = (new PolicyResolver())->resolveRead(Note::class, $context);
        $rows = Note::query()->orderBy('id')->get()->map(fn (Note $n) => RowSnapshot::fromModel($n))->all();
        $facts = (new RelationFactLoader(DB::connection()))->prepare($resolved->toPredicate(), $rows);

        $runtime = [];

        foreach ($rows as $row) {
            if ($resolved->decide($row, $facts, new PredicateEvaluator(), new StageReducer()) === Decision::Allow) {
                $runtime[] = $row->get('id');
            }
        }

        $this->assertSame($expected, $sql, 'SQL interpreter');
        $this->assertSame($expected, $runtime, 'runtime interpreter');
    }

    private function context(int $viewer, bool $staff = false): PrivacyContext
    {
        return new PrivacyContext(
            Viewer::identified($viewer),
            new Operation('note.list'),
            new ResourceScope(['tenant_id' => 7]),
            facts: new Facts(['is_staff' => $staff]),
            now: new DateTimeImmutable(self::NOW),
        );
    }
}

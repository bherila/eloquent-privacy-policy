<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ParentLink;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\Operation;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Context\Viewer;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema, seed data and policy registration for the action suite.
 *
 * Every table is prefixed act_ so that the suite can share one database with
 * the other suites, and only its own tables are dropped.
 */
abstract class ActionTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Privacy::flush();
        $this->dropTables();
        $this->createTables();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    protected function createTables(): void
    {
        Schema::create(Workspace::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('name');
        });

        Schema::create(Record::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('title');
            $table->boolean('locked')->nullable();
            $table->integer('revision')->default(0);
        });

        Schema::create(GrantFacts::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('ability');
        });
    }

    protected function dropTables(): void
    {
        Schema::dropIfExists(GrantFacts::TABLE);
        Schema::dropIfExists(Record::TABLE);
        Schema::dropIfExists(Workspace::TABLE);
    }

    /**
     * Viewer 1 owns workspace 1 and record 1; viewer 2 owns workspace 2 and
     * record 2. Only viewer 1 is granted write in workspace 1.
     */
    protected function seedFixtures(): void
    {
        DB::table(Workspace::TABLE)->insert([
            ['id' => 1, 'owner_id' => 1, 'name' => 'w1'],
            ['id' => 2, 'owner_id' => 2, 'name' => 'w2'],
            ['id' => 3, 'owner_id' => 1, 'name' => 'w3'],
        ]);

        DB::table(Record::TABLE)->insert([
            ['id' => 1, 'workspace_id' => 1, 'owner_id' => 1, 'title' => 'r1', 'locked' => false, 'revision' => 1],
            ['id' => 2, 'workspace_id' => 2, 'owner_id' => 2, 'title' => 'r2', 'locked' => false, 'revision' => 1],
            ['id' => 3, 'workspace_id' => 1, 'owner_id' => 2, 'title' => 'r3', 'locked' => true, 'revision' => 1],
        ]);

        DB::table(GrantFacts::TABLE)->insert([
            ['id' => 1, 'workspace_id' => 1, 'user_id' => 1, 'ability' => 'write'],
        ]);
    }

    /**
     * @param array<string, RuleSet> $actions
     */
    protected function registerRecordPolicy(array $actions, ?RuleSet $read = null): void
    {
        $policy = ModelPolicy::for(Record::class)->read($read ?? self::ownerRead('owner_id'));

        foreach ($actions as $name => $rules) {
            $policy = $policy->action($name, $rules);
        }

        Privacy::register(Record::class, $policy);
        Privacy::register(Workspace::class, ModelPolicy::for(Workspace::class)->read(self::ownerRead('owner_id')));
    }

    /** "The viewer owns this row", as a compilable read policy. */
    protected static function ownerRead(string $column): RuleSet
    {
        return RuleSet::define()->grant(
            Rule::of('owner', fn (PrivacyContext $c) => Col::int($column)->eq($c->viewer->intId())),
        );
    }

    /** @return ParentLink<Workspace> */
    protected static function workspaceLink(): ParentLink
    {
        return ParentLink::of('workspace_id', Workspace::class);
    }

    /** @param array<string, mixed> $facts */
    protected function context(int|string $viewer = 1, array $facts = [], string $operation = 'record.write'): PrivacyContext
    {
        return new PrivacyContext(
            Viewer::identified($viewer),
            new Operation($operation),
            facts: new Facts($facts),
        );
    }

    protected function anonymousContext(string $operation = 'record.write'): PrivacyContext
    {
        return new PrivacyContext(Viewer::anonymous(), new Operation($operation));
    }

    /** @return array<string, mixed> */
    protected function row(string $table, int $id): array
    {
        $row = DB::table($table)->where('id', '=', $id)->first();

        return $row === null ? [] : (array) $row;
    }
}

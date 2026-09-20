<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Context\Operation;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Context\Viewer;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Privacy;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Schema, seed data and policies shared by the test process and the child
 * processes it starts. Both sides must agree exactly, so both read them here.
 *
 * Tables are prefixed conc_ so the suite can share a database with the others.
 */
final class Fixture
{
    public const string SPACES = 'conc_spaces';

    public const string ITEMS = 'conc_items';

    public const string GRANTS = 'conc_grants';

    public const string EVENTS = 'conc_events';

    public const string ACTION = 'item.update';

    public const int VIEWER = 1;

    public const int SPACE = 1;

    public const int ITEM = 1;

    public const string WRITTEN = 'written by the action';

    public static function createTables(Builder $schema): void
    {
        self::dropTables($schema);

        $schema->create(self::SPACES, function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $schema->create(self::ITEMS, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('space_id');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('title');
            $table->integer('revision')->default(0);
        });

        $schema->create(self::GRANTS, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('space_id');
            $table->unsignedBigInteger('user_id');
        });

        // The barrier and the event log in one table: an insert both signals a
        // step and timestamps it, on a connection of its own so that nothing
        // here is rolled back with the work.
        $schema->create(self::EVENTS, function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->double('at');
        });
    }

    public static function dropTables(Builder $schema): void
    {
        foreach ([self::EVENTS, self::GRANTS, self::ITEMS, self::SPACES] as $table) {
            $schema->dropIfExists($table);
        }
    }

    public static function seed(ConnectionInterface $connection): void
    {
        $connection->table(self::SPACES)->insert(['id' => self::SPACE, 'name' => 'space']);
        $connection->table(self::ITEMS)->insert([
            'id' => self::ITEM, 'space_id' => self::SPACE, 'owner_id' => self::VIEWER, 'title' => 'before', 'revision' => 1,
        ]);
        $connection->table(self::GRANTS)->insert(['id' => 1, 'space_id' => self::SPACE, 'user_id' => self::VIEWER]);
    }

    /**
     * The action is authorised by one thing only: a grant row, read under lock
     * after the anchor has been taken. That makes the race the whole test.
     */
    public static function registerPolicies(): void
    {
        Privacy::flush();

        Privacy::register(Space::class, ModelPolicy::for(Space::class)->read(RuleSet::define()->terminal(Decision::Allow)));

        Privacy::register(Item::class, ModelPolicy::for(Item::class)
            ->read(RuleSet::define()->grant(
                Rule::of('owner', static fn (PrivacyContext $c) => Col::int('owner_id')->eq($c->viewer->intId())),
            ))
            ->action(self::ACTION, RuleSet::define()->grant(
                Rule::action('granted', static fn (PrivacyContext $c, ActionInput $i): Decision => $c->facts->bool('may_write')
                    ? Decision::Allow
                    : Decision::Skip),
            )));
    }

    public static function context(): PrivacyContext
    {
        return new PrivacyContext(Viewer::identified(self::VIEWER), new Operation('item.write'));
    }
}

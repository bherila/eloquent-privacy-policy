<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ActionExecutor;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\AnchorLock;
use BWH\EloquentPrivacyPolicy\Action\MissingLockedRow;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Privacy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * The locking half of the revocation protocol: which rows are taken, in which
 * order, and what happens when one of them is not there.
 */
final class AnchorLockingTest extends ActionTestCase
{
    public function test_anchors_are_locked_in_sorted_order_whatever_order_they_were_declared_in(): void
    {
        DB::table(GrantFacts::TABLE)->insert(['id' => 5, 'workspace_id' => 1, 'user_id' => 9, 'ability' => 'write']);

        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $action = RecordAction::update(1, ['title' => 'renamed'])->anchoredOn(
            new Anchor(Workspace::TABLE, 3),
            new Anchor(GrantFacts::TABLE, 5),
            new Anchor(Workspace::TABLE, 1),
            // The same row named twice is one lock, not two.
            new Anchor(Workspace::TABLE, 3),
        );

        $locks = $this->recordQueries(function () use ($action): void {
            (new ActionExecutor())->execute($action, $this->context(1));
        });

        $this->assertSame(
            [[GrantFacts::TABLE, 5], [Workspace::TABLE, 1], [Workspace::TABLE, 3]],
            $locks,
            'three anchors were named four times, in the wrong order',
        );
    }

    public function test_the_lock_is_a_real_for_update_on_a_real_engine(): void
    {
        $this->requiresRealEngine();

        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $statements = [];

        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $probe = new ProbeFacts();

        (new ActionExecutor())->execute(
            RecordAction::update(1, ['title' => 'renamed'])
                ->anchoredOn(new Anchor(Workspace::TABLE, 1))
                ->reading($probe),
            $this->context(1),
        );

        $locking = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'for update')));

        $this->assertNotSame([], $locking, 'the anchor read must be a locking read');
        $this->assertStringContainsString(Workspace::TABLE, $locking[0]);

        // And the facts behind the decision were read under a shared lock.
        $this->assertStringContainsString('lock in share mode', $probe->sql);

        $shared = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'lock in share mode') && str_contains($sql, GrantFacts::TABLE),
        ));

        $this->assertNotSame([], $shared, 'the grant behind the decision was not read under lock');
    }

    public function test_a_missing_anchor_row_fails_the_action(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $action = RecordAction::update(1, ['title' => 'renamed'])->anchoredOn(new Anchor(Workspace::TABLE, 404));

        try {
            (new ActionExecutor())->execute($action, $this->context(1));
            $this->fail('A missing anchor was treated as nothing to lock.');
        } catch (MissingLockedRow $failure) {
            $this->assertStringContainsString('nothing to lock', $failure->getMessage());
        }

        $this->assertSame('r1', $this->row(Record::TABLE, 1)['title']);
    }

    public function test_a_participating_grant_writer_takes_the_same_locks_in_the_same_order(): void
    {
        $anchors = [new Anchor(Workspace::TABLE, 3), new Anchor(Workspace::TABLE, 1)];

        $locks = $this->recordQueries(function () use ($anchors): void {
            $deleted = Privacy::withAnchors($anchors, static fn (): int => DB::table(GrantFacts::TABLE)->delete());

            $this->assertSame(1, $deleted, 'withAnchors returns what the writer returned');
        });

        $this->assertSame([[Workspace::TABLE, 1], [Workspace::TABLE, 3]], array_slice($locks, 0, 2));
        $this->assertSame(0, DB::table(GrantFacts::TABLE)->count());
    }

    public function test_a_grant_writer_whose_anchor_is_missing_does_not_run(): void
    {
        try {
            Privacy::withAnchors([new Anchor(Workspace::TABLE, 404)], static fn (): int => DB::table(GrantFacts::TABLE)->delete());
            $this->fail('A grant writer ran without its anchor.');
        } catch (MissingLockedRow) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, DB::table(GrantFacts::TABLE)->count());
    }

    public function test_the_order_is_total_and_de_duplicated(): void
    {
        $ordered = AnchorLock::sorted([
            new Anchor('act_b', 'x'),
            new Anchor('act_b', 2),
            new Anchor('act_a', 10),
            new Anchor('act_b', 'a'),
            new Anchor('act_a', 2),
            new Anchor('act_a', 10),
            new Anchor('act_a', 10, 'other_id'),
        ]);

        $this->assertSame(
            [['act_a', 2], ['act_a', 10], ['act_a', 10], ['act_b', 2], ['act_b', 'a'], ['act_b', 'x']],
            array_map(static fn (Anchor $anchor): array => [$anchor->table, $anchor->key], $ordered),
        );
    }


    public function test_the_two_parents_of_a_move_are_locked_in_key_order_whichever_way_it_goes(): void
    {
        $this->registerRecordPolicy(['record.move' => RuleSet::define()->terminal(Decision::Allow)]);

        $parentLocks = function (int $record, int $to): array {
            return array_values(array_map(
                static fn (array $lock): mixed => $lock[1],
                array_filter($this->recordQueries(function () use ($record, $to): void {
                    (new ActionExecutor())->execute(
                        RecordAction::update($record, ['workspace_id' => $to], 'record.move')->under(self::workspaceLink()),
                        $this->context(1),
                    );
                }), static fn (array $lock): bool => $lock[0] === Workspace::TABLE),
            ));
        };

        // Record 1 sits in workspace 1. Moving it up and then back down must
        // take the same two row locks in the same order, or two opposite moves
        // would deadlock.
        $this->assertSame([1, 3], $parentLocks(1, 3));
        $this->assertSame([1, 3], $parentLocks(1, 1));
    }

    /**
     * The (table, key) pairs of the locking reads a piece of work issued, in
     * the order the database saw them.
     *
     * @param callable(): void $work
     * @return list<array{string, mixed}>
     */
    private function recordQueries(callable $work): array
    {
        $locks = [];

        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            foreach ([Workspace::TABLE, GrantFacts::TABLE] as $table) {
                if (str_starts_with($query->sql, 'select') && str_contains($query->sql, $table)) {
                    $locks[] = [$table, $query->bindings[0] ?? null];
                }
            }
        });

        $work();

        return $locks;
    }
}

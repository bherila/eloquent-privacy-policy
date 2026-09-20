<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ActionExecutor;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\Receipt;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\ActionDenied;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An action inside a caller's transaction: a savepoint, a rollback that leaves
 * the outer transaction alive, and after-commit work that waits for the
 * outermost commit.
 */
final class NestedTransactionTest extends ActionTestCase
{
    public function test_after_commit_work_waits_for_the_outermost_commit(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $log = [];

        DB::transaction(function () use (&$log): void {
            (new ActionExecutor())->execute($this->allowed($log), $this->context(1));

            $log[] = 'inner returned';

            $this->assertSame(['inner returned'], $log, 'the callback ran inside the caller transaction');
        });

        $this->assertSame(['inner returned', 'after commit: 2'], $log);
        $this->assertSame('renamed', $this->row(Record::TABLE, 1)['title']);
    }

    public function test_after_commit_work_never_runs_when_the_outer_transaction_rolls_back(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $log = [];

        try {
            DB::transaction(function () use (&$log): void {
                (new ActionExecutor())->execute($this->allowed($log), $this->context(1));

                throw new RuntimeException('the caller changed its mind');
            });
        } catch (RuntimeException $failure) {
            $this->assertSame('the caller changed its mind', $failure->getMessage());
        }

        $this->assertSame([], $log);
        $this->assertSame('r1', $this->row(Record::TABLE, 1)['title']);
    }

    public function test_a_denial_rolls_back_to_the_savepoint_and_leaves_the_outer_transaction_usable(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        DB::transaction(function (): void {
            DB::table(Record::TABLE)->where('id', '=', 1)->update(['title' => 'by the caller']);

            try {
                (new ActionExecutor())->execute(
                    RecordAction::update(2, ['title' => 'taken over'])->anchoredOn(new Anchor(Workspace::TABLE, 2)),
                    $this->context(1),
                );
                $this->fail('A denied action was executed.');
            } catch (ActionDenied) {
                $this->addToAssertionCount(1);
            }

            // The savepoint rolled back, not the caller's transaction.
            $this->assertSame('by the caller', $this->row(Record::TABLE, 1)['title']);

            DB::table(Record::TABLE)->where('id', '=', 1)->update(['title' => 'still writing']);
        });

        $this->assertSame('still writing', $this->row(Record::TABLE, 1)['title']);
        $this->assertSame('r2', $this->row(Record::TABLE, 2)['title']);
    }

    public function test_an_action_nested_in_an_action_uses_its_own_savepoint(): void
    {
        $this->registerRecordPolicy([
            'record.update' => RuleSet::define()->grant(ActionRules::ownsTarget()),
            'record.nested' => RuleSet::define()->grant(ActionRules::always('open', Decision::Allow)),
        ]);

        $outer = RecordAction::update(1, ['title' => 'outer'])
            ->anchoredOn(new Anchor(Workspace::TABLE, 1))
            ->whilePersisting(function (Record $record): void {
                (new ActionExecutor())->execute(
                    RecordAction::update(2, ['title' => 'inner'], 'record.nested')->anchoredOn(new Anchor(Workspace::TABLE, 2)),
                    $this->context(1),
                );
            });

        (new ActionExecutor())->execute($outer, $this->context(1));

        $this->assertSame('outer', $this->row(Record::TABLE, 1)['title']);
        $this->assertSame('inner', $this->row(Record::TABLE, 2)['title']);
    }

    /** @param list<string> $log */
    private function allowed(array &$log): RecordAction
    {
        return RecordAction::update(1, ['title' => 'renamed'])
            ->anchoredOn(new Anchor(Workspace::TABLE, 1))
            ->thenAfterCommit(static function (Receipt $receipt) use (&$log): void {
                $log[] = 'after commit: '.(string) $receipt->version;
            });
    }
}

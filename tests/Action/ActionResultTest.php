<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ActionExecutor;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Privacy;
use Illuminate\Support\Facades\DB;

/**
 * What a caller learns about the row it just wrote: a receipt always, the row
 * itself only if the same context may read it.
 */
final class ActionResultTest extends ActionTestCase
{
    public function test_readable_returns_the_row_through_the_protected_read_path(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $result = (new ActionExecutor())->execute(
            RecordAction::update(1, ['title' => 'renamed'])->anchoredOn(new Anchor(Workspace::TABLE, 1)),
            $this->context(1),
        );

        $readable = $result->readable();

        $this->assertNotNull($readable);
        $this->assertSame('renamed', $readable->title);
        $this->assertTrue($readable->isPrivacyProtected(), 'the row comes back guarded, like any other read');
    }

    public function test_readable_is_null_for_a_caller_that_may_write_but_not_read(): void
    {
        // The action is authorised through the workspace the record lives in;
        // the read policy only shows a record to its own owner. Viewer 1 owns
        // workspace 1 but not record 3.
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsBothParents())]);

        $result = (new ActionExecutor())->execute(
            RecordAction::update(3, ['title' => 'renamed'])
                ->under(self::workspaceLink())
                ->anchoredOn(new Anchor(Workspace::TABLE, 1)),
            $this->context(1),
        );

        $this->assertSame('renamed', $this->row(Record::TABLE, 3)['title'], 'the write happened');
        $this->assertSame(3, $result->receipt->targetKey, 'and the receipt says so');
        $this->assertNull($result->readable(), 'but the caller may not read what it wrote');
    }

    public function test_readable_is_null_when_the_model_has_no_read_policy(): void
    {
        Privacy::register(Record::class, ModelPolicy::for(Record::class)
            ->action('record.update', RuleSet::define()->grant(ActionRules::ownsTarget())));

        $result = (new ActionExecutor())->execute(
            RecordAction::update(1, ['title' => 'renamed'])->anchoredOn(new Anchor(Workspace::TABLE, 1)),
            $this->context(1),
        );

        $this->assertNull($result->readable());
    }

    public function test_readable_re_queries_every_time_rather_than_holding_the_row(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $result = (new ActionExecutor())->execute(
            RecordAction::update(1, ['title' => 'renamed'])->anchoredOn(new Anchor(Workspace::TABLE, 1)),
            $this->context(1),
        );

        $this->assertNotNull($result->readable());

        DB::table(Record::TABLE)->where('id', '=', 1)->delete();

        $this->assertNull($result->readable(), 'the result held a key, not a model');
    }
}

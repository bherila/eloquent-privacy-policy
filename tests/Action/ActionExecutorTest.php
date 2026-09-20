<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ActionExecutor;
use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Action\ActionResult;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\DenialRecord;
use BWH\EloquentPrivacyPolicy\Action\Receipt;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\ActionDenied;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingContext;
use BWH\EloquentPrivacyPolicy\Exceptions\PolicyNotRegistered;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Privacy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

/**
 * The executor's sequence: what it loads, what it trusts, and what a denial
 * leaves behind.
 */
final class ActionExecutorTest extends ActionTestCase
{
    public function test_an_allowed_action_persists_and_returns_a_receipt(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $result = (new ActionExecutor())->execute(
            RecordAction::update(1, ['title' => 'renamed'])->anchoredOn(new Anchor(Workspace::TABLE, 1)),
            $this->context(1),
        );

        $this->assertSame('renamed', $this->row(Record::TABLE, 1)['title']);
        $this->assertSame('record.update', $result->receipt->action);
        $this->assertSame(Record::class, $result->receipt->model);
        $this->assertSame(1, $result->receipt->targetKey);
        $this->assertSame(2, $result->receipt->version, 'the receipt carries the version persist() left behind');
    }

    public function test_a_denial_changes_nothing_audits_once_and_runs_no_after_commit_callback(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $auditor = new RecordingAuditor();
        $ran = new Tripwire();

        $action = RecordAction::update(2, ['title' => 'taken over'])
            ->anchoredOn(new Anchor(Workspace::TABLE, 2))
            ->thenAfterCommit(static function (Receipt $receipt) use ($ran): void {
                $ran->trip();
            });

        try {
            (new ActionExecutor($auditor))->execute($action, $this->context(1));
            $this->fail('A denied action was executed.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('r2', $this->row(Record::TABLE, 2)['title'], 'the denied write was rolled back');
        $this->assertSame(1, $this->row(Record::TABLE, 2)['revision']);
        $this->assertFalse($ran->ran, 'a denied action runs no after-commit callback');

        $this->assertCount(1, $auditor->records);
        $record = $auditor->records[0];
        $this->assertSame('record.update', $record->action);
        $this->assertSame(Record::class, $record->model);
        $this->assertSame(2, $record->targetKey);
        $this->assertSame(1, $record->viewerId);
        $this->assertSame('user', $record->viewerType);
        $this->assertSame('record.write', $record->operation);

        // Identifiers only: the shape itself is the guarantee.
        $this->assertSame(
            ['action', 'model', 'targetKey', 'viewerId', 'viewerType', 'operation'],
            array_map(
                static fn (ReflectionProperty $property): string => $property->getName(),
                (new ReflectionClass(DenialRecord::class))->getProperties(),
            ),
        );
    }

    public function test_a_dirty_owner_field_cannot_authorise_itself(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $action = RecordAction::update(2, ['owner_id' => 1, 'title' => 'mine now'])
            ->anchoredOn(new Anchor(Workspace::TABLE, 2));

        $this->expectException(ActionDenied::class);

        try {
            (new ActionExecutor())->execute($action, $this->context(1));
        } finally {
            $this->assertSame(2, $this->row(Record::TABLE, 2)['owner_id']);
            $this->assertSame('r2', $this->row(Record::TABLE, 2)['title']);
        }
    }

    public function test_an_instance_the_caller_mutated_contributes_nothing_but_its_key(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        // The caller's own copy, tampered with in memory and never saved.
        $held = Record::query()->findOrFail(2);
        $held->owner_id = 1;

        $action = RecordAction::update($held->id, ['title' => 'mine now'])
            ->anchoredOn(new Anchor(Workspace::TABLE, 2));

        try {
            (new ActionExecutor())->execute($action, $this->context(1));
            $this->fail('An in-memory owner change authorised a write.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, $this->row(Record::TABLE, 2)['owner_id']);
        $this->assertSame('r2', $this->row(Record::TABLE, 2)['title']);
    }

    public function test_a_model_fetched_under_one_context_is_useless_under_another(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        // Viewer 1 may read record 1 ...
        $protected = Record::privacyQuery($this->context(1))->findOrFail(1);
        $this->assertTrue($protected->isPrivacyProtected());

        // ... and a read authorises no write, on that instance or any other.
        try {
            $protected->title = 'renamed';
            $protected->save();
            $this->fail('A protected instance was saved directly.');
        } catch (UnsupportedProtectedOperation) {
            $this->addToAssertionCount(1);
        }

        $action = RecordAction::update($protected->id, ['title' => 'renamed'])
            ->anchoredOn(new Anchor(Workspace::TABLE, 1));

        try {
            (new ActionExecutor())->execute($action, $this->context(2));
            $this->fail('A context-B action rode on a context-A fetch.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('r1', $this->row(Record::TABLE, 1)['title']);
    }

    public function test_reparenting_needs_the_proposed_parent_authorised(): void
    {
        $this->registerRecordPolicy(['record.move' => RuleSet::define()->grant(ActionRules::ownsBothParents())]);

        $move = fn (int $workspace): RecordAction => RecordAction::update(1, ['workspace_id' => $workspace], 'record.move')
            ->under(self::workspaceLink())
            ->anchoredOn(new Anchor(Workspace::TABLE, 1), new Anchor(Workspace::TABLE, $workspace));

        // Workspace 2 belongs to someone else: owning the workspace the record
        // is leaving says nothing about the one it would join.
        try {
            (new ActionExecutor())->execute($move(2), $this->context(1));
            $this->fail('A record was moved into a workspace the viewer does not own.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $this->row(Record::TABLE, 1)['workspace_id']);

        // Workspace 3 belongs to the viewer as well.
        (new ActionExecutor())->execute($move(3), $this->context(1));

        $this->assertSame(3, $this->row(Record::TABLE, 1)['workspace_id']);
    }

    public function test_the_proposed_parent_is_loaded_only_when_the_changes_touch_the_foreign_key(): void
    {
        /** @var list<array{mixed, mixed}> $seen */
        $seen = [];

        $this->registerRecordPolicy(['record.move' => RuleSet::define()->grant(
            Rule::action('capture', function (PrivacyContext $context, ActionInput $input) use (&$seen): Decision {
                $seen[] = [$input->parent?->get('id'), $input->proposedParent?->get('id')];

                return Decision::Allow;
            }),
        )]);

        $executor = new ActionExecutor();

        $executor->execute(
            RecordAction::update(1, ['title' => 'same place'], 'record.move')->under(self::workspaceLink()),
            $this->context(1),
        );

        $executor->execute(
            RecordAction::update(1, ['workspace_id' => 3], 'record.move')->under(self::workspaceLink()),
            $this->context(1),
        );

        $this->assertSame([[1, null], [1, 3]], $seen);
    }

    public function test_a_creation_is_authorised_through_the_creation_parent(): void
    {
        $this->registerRecordPolicy(['record.create' => RuleSet::define()->grant(ActionRules::ownsCreationParent())]);

        $executor = new ActionExecutor();
        $before = DB::table(Record::TABLE)->count();

        try {
            $executor->execute(
                RecordAction::create(['workspace_id' => 2, 'owner_id' => 1, 'title' => 'theirs'])
                    ->under(self::workspaceLink())
                    ->anchoredOn(new Anchor(Workspace::TABLE, 2)),
                $this->context(1),
            );
            $this->fail('A record was created in a workspace the viewer does not own.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, DB::table(Record::TABLE)->count(), 'a denied creation inserts nothing');

        $result = $executor->execute(
            RecordAction::create(['workspace_id' => 3, 'owner_id' => 1, 'title' => 'mine'])
                ->under(self::workspaceLink())
                ->anchoredOn(new Anchor(Workspace::TABLE, 3)),
            $this->context(1),
        );

        $this->assertNotNull($result->receipt->targetKey);
        $this->assertSame($before + 1, DB::table(Record::TABLE)->count());
        $this->assertSame('mine', $this->row(Record::TABLE, (int) $result->receipt->targetKey)['title']);
    }

    public function test_a_validation_failure_rolls_the_action_back(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $ran = new Tripwire();

        $action = RecordAction::update(1, ['title' => 'renamed'])
            ->anchoredOn(new Anchor(Workspace::TABLE, 1))
            ->validatedBy(static fn (): never => throw new RuntimeException('title is taken'))
            ->thenAfterCommit(static function (Receipt $receipt) use ($ran): void {
                $ran->trip();
            });

        try {
            (new ActionExecutor())->execute($action, $this->context(1));
            $this->fail('A failing validate() did not stop the action.');
        } catch (RuntimeException $failure) {
            $this->assertSame('title is taken', $failure->getMessage());
        }

        $this->assertSame('r1', $this->row(Record::TABLE, 1)['title']);
        $this->assertFalse($ran->ran);
    }

    public function test_a_null_context_is_an_error(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $this->expectException(MissingContext::class);

        (new ActionExecutor())->execute(RecordAction::update(1, ['title' => 'x']), null);
    }

    public function test_a_read_policy_never_authorises_a_write(): void
    {
        // A read policy that would happily show record 1 to viewer 1, and no
        // policy at all for the action being attempted.
        Privacy::register(Record::class, ModelPolicy::for(Record::class)
            ->read(self::ownerRead('owner_id'))
            ->action('record.rename', RuleSet::define()->grant(ActionRules::ownsTarget())));

        $this->assertNotNull(Record::privacyQuery($this->context(1))->find(1));

        $this->expectException(PolicyNotRegistered::class);

        (new ActionExecutor())->execute(RecordAction::update(1, ['title' => 'x']), $this->context(1));
    }

    public function test_facts_are_resolved_inside_the_transaction_after_the_locks(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::hasWriteGrant())]);

        $probe = new ProbeFacts();
        $tables = [];

        DB::listen(static function (QueryExecuted $query) use (&$tables): void {
            foreach ([Workspace::TABLE, Record::TABLE, GrantFacts::TABLE] as $table) {
                if (str_starts_with($query->sql, 'select') && str_contains($query->sql, $table)) {
                    $tables[] = $table;
                }
            }
        });

        (new ActionExecutor())->execute(
            RecordAction::update(1, ['title' => 'renamed'])
                ->anchoredOn(new Anchor(Workspace::TABLE, 1))
                ->reading($probe),
            $this->context(1),
        );

        $this->assertGreaterThanOrEqual(1, $probe->transactionLevel, 'facts were resolved outside the transaction');
        $this->assertNotNull($probe->lock, 'the fact provider was handed an unlocked query');
        $this->assertSame(
            [Workspace::TABLE, Record::TABLE, GrantFacts::TABLE],
            $tables,
            'facts must be read after the anchor lock and the pre-state load',
        );
    }

    public function test_the_result_never_carries_the_persisted_model(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::ownsTarget())]);

        $result = (new ActionExecutor())->execute(
            RecordAction::update(1, ['title' => 'renamed'])->anchoredOn(new Anchor(Workspace::TABLE, 1)),
            $this->context(1),
        );

        foreach ((new ReflectionClass(ActionResult::class))->getProperties() as $property) {
            $this->assertNotInstanceOf(Model::class, $property->getValue($result), $property->getName());
        }

        foreach ((new ReflectionClass(ActionResult::class))->getMethods() as $method) {
            $type = $method->getReturnType();
            $returnsModel = $type instanceof ReflectionNamedType && is_a((string) $type, Model::class, true);

            $this->assertTrue(
                ! $returnsModel || $method->getName() === 'readable',
                sprintf('%s() hands out a model', $method->getName()),
            );
        }
    }
}

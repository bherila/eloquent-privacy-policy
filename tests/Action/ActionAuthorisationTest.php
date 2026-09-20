<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ActionExecutor;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\ActionDenied;
use BWH\EloquentPrivacyPolicy\Exceptions\InvalidOutcome;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingFact;
use BWH\EloquentPrivacyPolicy\Exceptions\StageEvaluationFailed;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How an action's rule set is reduced: the same five stages as a read, over an
 * ActionInput, with the persisted pre-state as the only row a predicate rule
 * may look at.
 */
final class ActionAuthorisationTest extends ActionTestCase
{
    public function test_a_mandatory_deny_beats_a_privileged_allow(): void
    {
        $this->assertDenies(
            RuleSet::define()
                ->mandatory(ActionRules::always('boundary', Decision::Deny))
                ->privileged(ActionRules::always('staff', Decision::Allow)),
        );
    }

    public function test_a_deny_beats_a_grant(): void
    {
        $this->assertDenies(
            RuleSet::define()
                ->deny(ActionRules::always('embargo', Decision::Deny))
                ->grant(ActionRules::always('owner', Decision::Allow)),
        );
    }

    public function test_a_privileged_allow_is_not_reached_through_a_mandatory_deny_but_does_skip_a_deny(): void
    {
        $this->assertAllows(
            RuleSet::define()
                ->privileged(ActionRules::always('staff', Decision::Allow))
                ->deny(ActionRules::always('embargo', Decision::Deny)),
        );
    }

    public function test_the_terminal_defaults_to_deny(): void
    {
        $this->assertDenies(RuleSet::define());
    }

    public function test_a_terminal_allow_does_not_bypass_a_deny(): void
    {
        $this->assertDenies(
            RuleSet::define()->deny(ActionRules::always('embargo', Decision::Deny))->terminal(Decision::Allow),
        );

        $this->assertAllows(RuleSet::define()->terminal(Decision::Allow));
    }

    public function test_an_outcome_a_stage_does_not_permit_fails_the_stage_and_rolls_back(): void
    {
        $failure = $this->assertFails(
            RuleSet::define()->grant(ActionRules::always('confused', Decision::Deny)),
        );

        $this->assertInstanceOf(InvalidOutcome::class, $failure->getPrevious());
        $this->assertSame('grant', $failure->stage);
    }

    public function test_a_missing_fact_fails_the_stage_and_rolls_back(): void
    {
        $failure = $this->assertFails(
            RuleSet::define()->grant(ActionRules::hasWriteGrant()),
        );

        $this->assertInstanceOf(MissingFact::class, $failure->getPrevious());
    }

    public function test_an_error_is_not_reported_to_the_denial_auditor(): void
    {
        // Only a decision is a denial. An error is a failure, and an audit
        // trail that calls it a denial says something untrue.
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::hasWriteGrant())]);

        $auditor = new RecordingAuditor();

        try {
            (new ActionExecutor($auditor))->execute($this->action(), $this->context(1));
            $this->fail('A stage failure did not stop the action.');
        } catch (StageEvaluationFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $auditor->records);
    }

    public function test_a_predicate_rule_sees_the_persisted_pre_state_not_the_proposed_changes(): void
    {
        $locked = Rule::of('locked', static fn (): Predicate => Col::bool('locked')->eq(true));

        $rules = RuleSet::define()
            ->deny($locked)
            ->grant(ActionRules::ownsTarget());

        $this->registerRecordPolicy(['record.update' => $rules]);

        // Record 3 is locked in the database; proposing to unlock it does not
        // unlock it for the purposes of authorising this very action.
        try {
            (new ActionExecutor())->execute(
                RecordAction::update(3, ['locked' => false, 'title' => 'unlocked'])->anchoredOn(new Anchor(Workspace::TABLE, 1)),
                $this->context(2),
            );
            $this->fail('A proposed change authorised itself.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('r3', $this->row(Record::TABLE, 3)['title']);

        // Record 1 is not locked; proposing to lock it is allowed, and the
        // proposal is what gets written.
        (new ActionExecutor())->execute(
            RecordAction::update(1, ['locked' => true, 'title' => 'locked now'])->anchoredOn(new Anchor(Workspace::TABLE, 1)),
            $this->context(1),
        );

        $this->assertSame('locked now', $this->row(Record::TABLE, 1)['title']);
    }

    public function test_a_predicate_rule_that_reads_a_relationship_is_rejected(): void
    {
        $viaExists = Rule::of('shared', static fn (): Predicate => Predicate::any(
            Col::bool('locked')->eq(false),
            Exists::in(GrantFacts::TABLE)->match('workspace_id', 'workspace_id')->where(Col::int('user_id')->eq(1)),
        ));

        $viaParent = Rule::of('parent', static fn (): Predicate => Predicate::not(ViaParent::of('workspace_id', Workspace::class)));

        foreach ([$viaExists, $viaParent] as $rule) {
            $failure = $this->assertFails(RuleSet::define()->grant($rule));

            $this->assertInstanceOf(UnsupportedProtectedOperation::class, $failure->getPrevious());
            $this->assertStringContainsString('read under lock', $failure->getPrevious()->getMessage());
        }
    }

    public function test_a_predicate_rule_cannot_authorise_a_creation(): void
    {
        $this->registerRecordPolicy([
            'record.create' => RuleSet::define()->grant(
                Rule::of('owner', fn (PrivacyContext $c): Predicate => Col::int('owner_id')->eq($c->viewer->intId())),
            ),
        ]);

        $before = DB::table(Record::TABLE)->count();

        try {
            (new ActionExecutor())->execute(
                RecordAction::create(['workspace_id' => 1, 'owner_id' => 1, 'title' => 'new']),
                $this->context(1),
            );
            $this->fail('A predicate rule authorised a creation.');
        } catch (StageEvaluationFailed $failure) {
            $this->assertInstanceOf(UnsupportedProtectedOperation::class, $failure->getPrevious());
        }

        $this->assertSame($before, DB::table(Record::TABLE)->count());
    }

    public function test_an_anonymous_viewer_is_denied_before_any_rule_runs(): void
    {
        $tripwire = new Tripwire();

        $this->registerRecordPolicy(['record.update' => RuleSet::define()
            ->mandatory(ActionRules::tripwire('reached', $tripwire))
            ->grant(ActionRules::always('open', Decision::Allow))]);

        try {
            (new ActionExecutor())->execute($this->action(), $this->anonymousContext());
            $this->fail('An anonymous viewer was allowed to write.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($tripwire->ran, 'no rule runs for an anonymous viewer on a rule set that does not allow one');
        $this->assertSame('r1', $this->row(Record::TABLE, 1)['title']);
    }

    public function test_a_rule_set_that_allows_anonymous_viewers_runs_its_rules(): void
    {
        $tripwire = new Tripwire();

        $this->registerRecordPolicy(['record.update' => RuleSet::define()
            ->grant(ActionRules::tripwire('reached', $tripwire, Decision::Allow))
            ->allowAnonymous()]);

        (new ActionExecutor())->execute($this->action(), $this->anonymousContext());

        $this->assertTrue($tripwire->ran);
        $this->assertSame('renamed', $this->row(Record::TABLE, 1)['title']);
    }

    public function test_facts_resolved_under_lock_decide_the_action(): void
    {
        $this->registerRecordPolicy(['record.update' => RuleSet::define()->grant(ActionRules::hasWriteGrant())]);

        $action = fn (): RecordAction => RecordAction::update(1, ['title' => 'renamed'])
            ->under(self::workspaceLink())
            ->anchoredOn(new Anchor(Workspace::TABLE, 1))
            ->reading(new GrantFacts());

        (new ActionExecutor())->execute($action(), $this->context(1));
        $this->assertSame('renamed', $this->row(Record::TABLE, 1)['title']);

        // The same action once the grant is gone.
        DB::table(GrantFacts::TABLE)->delete();

        $this->expectException(ActionDenied::class);

        (new ActionExecutor())->execute($action(), $this->context(1));
    }

    private function action(): RecordAction
    {
        return RecordAction::update(1, ['title' => 'renamed'])->anchoredOn(new Anchor(Workspace::TABLE, 1));
    }

    private function assertAllows(RuleSet $rules): void
    {
        $this->registerRecordPolicy(['record.update' => $rules]);

        (new ActionExecutor())->execute($this->action(), $this->context(1));

        $this->assertSame('renamed', $this->row(Record::TABLE, 1)['title']);
    }

    private function assertDenies(RuleSet $rules): void
    {
        $this->registerRecordPolicy(['record.update' => $rules]);

        try {
            (new ActionExecutor())->execute($this->action(), $this->context(1));
            $this->fail('The action was allowed.');
        } catch (ActionDenied) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('r1', $this->row(Record::TABLE, 1)['title']);
    }

    /** Asserts the action failed (rather than being denied) and changed nothing. */
    private function assertFails(RuleSet $rules): StageEvaluationFailed
    {
        $this->registerRecordPolicy(['record.update' => $rules]);

        try {
            (new ActionExecutor())->execute($this->action(), $this->context(1));
            $this->fail('A failing rule set did not stop the action.');
        } catch (StageEvaluationFailed $failure) {
            $this->assertSame('r1', $this->row(Record::TABLE, 1)['title'], 'the failed action was rolled back');

            return $failure;
        } catch (Throwable $other) {
            $this->fail(sprintf('Expected a stage failure, got %s: %s', $other::class, $other->getMessage()));
        }
    }
}

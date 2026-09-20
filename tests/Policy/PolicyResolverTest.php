<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Policy;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\PolicyCycle;
use BWH\EloquentPrivacyPolicy\Exceptions\PolicyNotRegistered;
use BWH\EloquentPrivacyPolicy\Exceptions\StageEvaluationFailed;
use BWH\EloquentPrivacyPolicy\Exceptions\UncompilablePolicy;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\PolicyResolver;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\AllOf;
use BWH\EloquentPrivacyPolicy\Predicate\AnyOf;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Negation;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\Patient;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd\CycleLeft;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd\Unpoliced;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Question;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support\PredicateDescriber;
use RuntimeException;

/** Contract section 4.4: resolve, then interpret. */
final class PolicyResolverTest extends FixtureTestCase
{
    public function test_the_composed_predicate_has_the_shape_the_contract_names(): void
    {
        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()
                ->mandatory(Rule::of('h', fn () => Col::int('workspace_id')->eq(1)))
                ->privileged(Rule::of('p', fn () => Col::int('author_id')->eq(9)))
                ->deny(Rule::of('d', fn () => Col::bool('archived')->eq(true)))
                ->grant(Rule::of('a', fn () => Col::bool('is_faq')->eq(true))),
        ));

        $predicate = (new PolicyResolver())->resolveRead(Question::class, $this->context(1))->toPredicate();

        // H AND ( P OR ( NOT D AND ( A OR T ) ) ), T = never (default terminal Deny)
        $this->assertInstanceOf(AllOf::class, $predicate);
        $this->assertCount(2, $predicate->predicates);

        $or = $predicate->predicates[1];
        $this->assertInstanceOf(AnyOf::class, $or);
        $this->assertCount(2, $or->predicates);

        $notDAndA = $or->predicates[1];
        $this->assertInstanceOf(AllOf::class, $notDAndA);
        $this->assertInstanceOf(Negation::class, $notDAndA->predicates[0]);

        $this->assertSame(
            '(workspace_id:int = 1 AND (author_id:int = 9 OR (NOT archived:bool = true AND is_faq:bool = true)))',
            PredicateDescriber::describe($predicate),
        );
    }

    public function test_a_terminal_allow_still_sits_under_not_deny_and_under_mandatory(): void
    {
        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()
                ->terminal(Decision::Allow)
                ->mandatory(Rule::of('h', fn () => Col::int('workspace_id')->eq(1)))
                ->deny(Rule::of('d', fn () => Col::bool('archived')->eq(true))),
        ));

        $this->assertSame(
            '(workspace_id:int = 1 AND NOT archived:bool = true)',
            PredicateDescriber::describe(
                (new PolicyResolver())->resolveRead(Question::class, $this->context(1))->toPredicate(),
            ),
        );
    }

    public function test_a_privileged_grant_is_not_flattened_into_an_ordinary_grant(): void
    {
        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()
                ->privileged(Rule::of('p', fn () => Col::int('author_id')->eq(9)))
                ->deny(Rule::of('d', fn () => Col::bool('archived')->eq(true)))
                ->grant(Rule::of('a', fn () => Col::bool('is_faq')->eq(true))),
        ));

        // The privileged disjunct must sit outside NOT D, not beside the grant.
        $this->assertSame(
            '(author_id:int = 9 OR (NOT archived:bool = true AND is_faq:bool = true))',
            PredicateDescriber::describe(
                (new PolicyResolver())->resolveRead(Question::class, $this->context(1))->toPredicate(),
            ),
        );
    }

    public function test_a_viewer_only_rule_folds_to_a_constant_and_disappears_from_the_sql(): void
    {
        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()
                ->privileged(Rule::of('staff', fn (PrivacyContext $c) => Predicate::constant($c->facts->bool('is_staff'))))
                ->grant(Rule::of('faq', fn () => Col::bool('is_faq')->eq(true))),
        ));

        $notStaff = (new PolicyResolver())->resolveRead(Question::class, $this->context(1, ['is_staff' => false]));
        $staff = (new PolicyResolver())->resolveRead(Question::class, $this->context(1, ['is_staff' => true]));

        $this->assertSame('is_faq:bool = true', PredicateDescriber::describe($notStaff->toPredicate()));
        $this->assertTrue($staff->toPredicate()->isAlways(), 'a privileged constant true must fold to always()');
    }

    public function test_resolution_is_stage_ordered_and_stops_at_a_decisive_constant(): void
    {
        $ran = [];

        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()
                ->privileged(Rule::of('staff', function () use (&$ran): Predicate {
                    $ran[] = 'staff';

                    return Predicate::always();
                }))
                ->deny(Rule::of('later', function () use (&$ran): Predicate {
                    $ran[] = 'later';

                    throw new RuntimeException('a later stage was resolved');
                })),
        ));

        $resolved = (new PolicyResolver())->resolveRead(Question::class, $this->context(1));

        $this->assertTrue($resolved->toPredicate()->isAlways());
        $this->assertSame(['staff'], $ran);
    }

    public function test_a_mandatory_rule_that_can_never_hold_stops_resolution_too(): void
    {
        $ran = [];

        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()
                ->mandatory(Rule::of('boundary', fn () => Predicate::never()))
                ->grant(Rule::of('later', function () use (&$ran): Predicate {
                    $ran[] = 'later';

                    return Predicate::always();
                })),
        ));

        $resolved = (new PolicyResolver())->resolveRead(Question::class, $this->context(1));

        $this->assertTrue($resolved->toPredicate()->isNever());
        $this->assertSame([], $ran);
    }

    public function test_context_errors_surface_at_resolve_time_wrapped_by_stage(): void
    {
        Privacy::register(Question::class, ModelPolicy::for(Question::class)->read(
            RuleSet::define()->grant(
                Rule::of('needs_fact', fn (PrivacyContext $c) => Predicate::constant($c->facts->bool('absent'))),
                Rule::of('fine', fn () => Predicate::never()),
            ),
        ));

        try {
            (new PolicyResolver())->resolveRead(Question::class, $this->context(1));
            $this->fail('A missing fact did not fail resolution.');
        } catch (StageEvaluationFailed $failure) {
            $this->assertSame('grant', $failure->stage);
            $this->assertSame(['needs_fact'], array_keys($failure->failures));
        }
    }

    // ----- ViaParent ---------------------------------------------------------

    public function test_via_parent_expands_to_an_exists_and_adds_the_soft_delete_guard(): void
    {
        $predicate = (new PolicyResolver())->resolveRead(ClinicalRecord::class, $this->context(1))->toPredicate();
        $rendered = PredicateDescriber::describe($predicate);

        $this->assertStringNotContainsString('VIA_PARENT', $rendered, 'ViaParent must be expanded before interpretation');
        $this->assertStringContainsString('EXISTS fx_patients ON [patient_id=id]', $rendered);
        $this->assertStringContainsString('deleted_at IS NULL', $rendered);
        $this->assertStringContainsString('EXISTS fx_patient_grants', $rendered, 'the parent policy is expanded too');
    }

    public function test_via_parent_honours_an_explicit_owner_key(): void
    {
        $predicate = (new PolicyResolver())
            ->resolveRead(\BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\FeeSchedule::class, $this->context(1))
            ->toPredicate();

        $this->assertStringContainsString(
            'EXISTS fx_accounts ON [acct_id=acct_id]',
            PredicateDescriber::describe($predicate),
        );
    }

    /**
     * The cycle is raised inside a rule closure, so it arrives wrapped in the
     * stage failure like any other rule error; what matters is that expansion
     * terminates with a PolicyCycle rather than recursing.
     */
    public function test_a_via_parent_cycle_is_detected(): void
    {
        try {
            (new PolicyResolver())->resolveRead(CycleLeft::class, $this->context(1));
            $this->fail('A mutually recursive ViaParent pair did not raise a cycle.');
        } catch (StageEvaluationFailed $failure) {
            $this->assertInstanceOf(PolicyCycle::class, $this->rootCause($failure));
        }
    }

    public function test_a_self_referencing_via_parent_is_a_cycle(): void
    {
        Privacy::register(Patient::class, ModelPolicy::for(Patient::class)->read(
            RuleSet::define()->grant(Rule::of('self', fn () => ViaParent::of('owner_id', Patient::class))),
        ));

        try {
            (new PolicyResolver())->resolveRead(Patient::class, $this->context(1));
            $this->fail('A self-referencing ViaParent did not raise a cycle.');
        } catch (StageEvaluationFailed $failure) {
            $cycle = $this->rootCause($failure);

            $this->assertInstanceOf(PolicyCycle::class, $cycle);
            $this->assertStringContainsString('Policy cycle', $cycle->getMessage());
        }
    }

    /** Unwrap nested stage failures down to the exception a rule actually threw. */
    private function rootCause(StageEvaluationFailed $failure): \Throwable
    {
        $failures = $failure->failures;

        $this->assertNotSame([], $failures);

        $cause = reset($failures);

        $this->assertInstanceOf(\Throwable::class, $cause);

        return $cause instanceof StageEvaluationFailed ? $this->rootCause($cause) : $cause;
    }

    // ----- policies that cannot be used for reads ---------------------------

    public function test_a_model_without_a_policy_is_a_policy_not_registered(): void
    {
        $this->expectException(PolicyNotRegistered::class);

        Unpoliced::privacyQuery($this->context(1));
    }

    public function test_a_policy_with_no_read_rule_set_is_a_policy_not_registered(): void
    {
        Privacy::register(Unpoliced::class, ModelPolicy::for(Unpoliced::class));

        $this->expectException(PolicyNotRegistered::class);

        Unpoliced::privacyQuery($this->context(1));
    }

    public function test_a_read_policy_containing_an_action_rule_is_uncompilable(): void
    {
        $this->expectException(UncompilablePolicy::class);
        $this->expectExceptionMessage('runtime-only');

        ModelPolicy::for(Question::class)->read(
            RuleSet::define()->grant(Rule::action('runtime_only', static fn (): Decision => Decision::Allow)),
        );
    }

    public function test_an_action_rule_set_is_kept_separately_from_the_read_one(): void
    {
        $policy = ModelPolicy::for(Question::class)
            ->read(RuleSet::define()->grant(Rule::of('faq', fn () => Col::bool('is_faq')->eq(true))))
            ->action('publish', RuleSet::define()->grant(Rule::action('a', static fn (): Decision => Decision::Allow)));

        $this->assertSame(['a'], array_keys($policy->actionRules('publish')->rulesOf(\BWH\EloquentPrivacyPolicy\Policy\Stage::Grant)));

        $this->expectException(PolicyNotRegistered::class);
        $policy->actionRules('delete');
    }

    public function test_an_unexpanded_via_parent_is_refused_by_both_interpreters(): void
    {
        $via = ViaParent::of('patient_id', Patient::class);

        try {
            (new \BWH\EloquentPrivacyPolicy\Sql\PredicateCompiler())
                ->apply(\Illuminate\Support\Facades\DB::table('fx_patients'), $via, 'fx_patients');
            $this->fail('The compiler accepted an unexpanded ViaParent.');
        } catch (UncompilablePolicy $exception) {
            $this->assertStringContainsString('expanded by the resolver', $exception->getMessage());
        }

        $this->expectException(UncompilablePolicy::class);
        (new \BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator())->evaluate(
            $via,
            new \BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot([]),
            new \BWH\EloquentPrivacyPolicy\Runtime\ExistsFacts(),
        );
    }

    public function test_policy_definitions_are_cached_per_model_but_never_a_context(): void
    {
        $first = Privacy::policyFor(Question::class);
        $second = Privacy::policyFor(Question::class);

        $this->assertSame($first, $second, 'context-free definitions may be cached');

        Privacy::flush();

        $this->assertNotSame($first, Privacy::policyFor(Question::class));
    }

    public function test_an_exists_whose_inner_policy_folds_to_never_disappears(): void
    {
        Privacy::register(Patient::class, ModelPolicy::for(Patient::class)->read(
            RuleSet::define()->grant(Rule::of('nobody', fn () => Predicate::never())),
        ));

        $predicate = (new PolicyResolver())->resolveRead(ClinicalRecord::class, $this->context(1))->toPredicate();

        $this->assertTrue($predicate->isNever());
        $this->assertNotInstanceOf(Exists::class, $predicate);
    }
}

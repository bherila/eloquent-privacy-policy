<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Policy;

use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\InvalidOutcome;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingFact;
use BWH\EloquentPrivacyPolicy\Exceptions\PrivacyException;
use BWH\EloquentPrivacyPolicy\Exceptions\StageEvaluationFailed;
use BWH\EloquentPrivacyPolicy\Policy\ActionRule;
use BWH\EloquentPrivacyPolicy\Policy\PredicateRule;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Policy\Stage;
use BWH\EloquentPrivacyPolicy\Policy\StageReducer;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Contract section 2: the stage table, entered-stage completion, and the rule
 * that an error is never a Skip and never an implicit Allow.
 *
 * The reducer takes the evaluation callback as an argument, so these tests
 * drive outcomes directly instead of going through predicates.
 */
final class StageReducerTest extends TestCase
{
    /** @var list<string> rules that ran during the last reduce(), in call order */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ran = [];
    }

    // ----- the outcome table -------------------------------------------------

    public function test_mandatory_deny_wins_over_every_later_stage(): void
    {
        $decision = $this->reduce([
            'mandatory' => ['boundary' => Decision::Deny],
            'privileged' => ['staff' => Decision::Allow],
            'grant' => ['owner' => Decision::Allow],
        ], Decision::Allow);

        $this->assertSame(Decision::Deny, $decision);
        $this->assertSame(['mandatory:boundary'], $this->ran, 'later stages must not be entered');
    }

    public function test_mandatory_skip_means_the_boundary_is_satisfied(): void
    {
        $this->assertSame(Decision::Allow, $this->reduce([
            'mandatory' => ['boundary' => Decision::Skip],
            'grant' => ['owner' => Decision::Allow],
        ]));
    }

    public function test_privileged_allow_short_circuits_deny_and_grant(): void
    {
        $decision = $this->reduce([
            'mandatory' => ['boundary' => Decision::Skip],
            'privileged' => ['staff' => Decision::Allow],
            'deny' => ['locked' => Decision::Deny],
            'grant' => ['owner' => Decision::Skip],
        ]);

        $this->assertSame(Decision::Allow, $decision);
        $this->assertSame(['mandatory:boundary', 'privileged:staff'], $this->ran);
    }

    public function test_deny_wins_over_grant(): void
    {
        $decision = $this->reduce([
            'deny' => ['locked' => Decision::Deny],
            'grant' => ['owner' => Decision::Allow],
        ]);

        $this->assertSame(Decision::Deny, $decision);
        $this->assertSame(['deny:locked'], $this->ran);
    }

    public function test_grant_allow_wins_over_a_terminal_deny(): void
    {
        $this->assertSame(Decision::Allow, $this->reduce([
            'grant' => ['a' => Decision::Skip, 'b' => Decision::Allow],
        ], Decision::Deny));
    }

    public function test_the_terminal_defaults_to_deny_when_everything_skips(): void
    {
        $this->assertSame(Decision::Deny, $this->reduce([
            'mandatory' => ['boundary' => Decision::Skip],
            'privileged' => ['staff' => Decision::Skip],
            'deny' => ['locked' => Decision::Skip],
            'grant' => ['owner' => Decision::Skip],
        ]));

        $this->assertSame(Decision::Deny, RuleSet::define()->terminal);
    }

    public function test_a_terminal_allow_bypasses_neither_deny_nor_mandatory(): void
    {
        $this->assertSame(Decision::Deny, $this->reduce([
            'deny' => ['locked' => Decision::Deny],
        ], Decision::Allow));

        $this->assertSame(Decision::Deny, $this->reduce([
            'mandatory' => ['boundary' => Decision::Deny],
        ], Decision::Allow));

        $this->assertSame(Decision::Allow, $this->reduce([
            'mandatory' => ['boundary' => Decision::Skip],
            'deny' => ['locked' => Decision::Skip],
        ], Decision::Allow));
    }

    public function test_the_terminal_cannot_be_skip(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuleSet::define()->terminal(Decision::Skip);
    }

    // ----- invalid outcomes --------------------------------------------------

    /**
     * Every (stage, outcome) pair the stage table forbids must raise
     * InvalidOutcome, wrapped in StageEvaluationFailed.
     */
    public function test_every_outcome_a_stage_forbids_is_an_invalid_outcome(): void
    {
        $forbidden = [
            'mandatory' => Decision::Allow,
            'privileged' => Decision::Deny,
            'deny' => Decision::Allow,
            'grant' => Decision::Deny,
        ];

        foreach ($forbidden as $stage => $outcome) {
            $failure = $this->reduceExpectingFailure([$stage => ['r' => $outcome]]);

            $this->assertSame($stage, $failure->stage);
            $this->assertSame(['r'], array_keys($failure->failures));
            $this->assertInstanceOf(InvalidOutcome::class, $failure->failures['r']);
            $this->assertInstanceOf(InvalidOutcome::class, $failure->getPrevious());
        }
    }

    public function test_every_outcome_a_stage_permits_is_accepted(): void
    {
        foreach (Stage::cases() as $stage) {
            $this->assertTrue($stage->permits(Decision::Skip), $stage->value.' must permit Skip');
            $this->assertTrue($stage->permits($stage->decisive()));
            $this->assertFalse($stage->permits(
                $stage->decisive() === Decision::Allow ? Decision::Deny : Decision::Allow,
            ));
        }
    }

    // ----- entered-stage completion -----------------------------------------

    public function test_a_stage_where_one_rule_allows_and_another_throws_is_an_error(): void
    {
        $failure = $this->reduceExpectingFailure([
            'grant' => [
                'allows' => Decision::Allow,
                'throws' => new MissingFact('no such fact'),
            ],
        ]);

        $this->assertSame('grant', $failure->stage);
        $this->assertSame(['throws'], array_keys($failure->failures));
        $this->assertSame(
            ['grant:allows', 'grant:throws'],
            $this->ran,
            'every rule of an entered stage must still run',
        );
    }

    public function test_a_throwing_rule_in_a_stage_that_is_never_entered_does_not_surface(): void
    {
        $decision = $this->reduce([
            'mandatory' => ['boundary' => Decision::Deny],
            'grant' => ['broken' => new RuntimeException('must not run')],
        ]);

        $this->assertSame(Decision::Deny, $decision);
        $this->assertSame(['mandatory:boundary'], $this->ran);
    }

    public function test_all_failures_of_an_entered_stage_are_wrapped_and_ordered_by_rule_id(): void
    {
        $failure = $this->reduceExpectingFailure([
            'grant' => [
                'zulu' => new MissingFact('z'),
                'alpha' => new MissingFact('a'),
                'mike' => Decision::Skip,
            ],
        ]);

        $this->assertSame(['alpha', 'zulu'], array_keys($failure->failures));
        $this->assertStringContainsString('alpha, zulu', $failure->getMessage());
        $this->assertInstanceOf(PrivacyException::class, $failure);
    }

    public function test_a_failure_in_an_earlier_stage_stops_before_the_next_one(): void
    {
        $failure = $this->reduceExpectingFailure([
            'mandatory' => ['boundary' => new MissingFact('scope')],
            'privileged' => ['staff' => new RuntimeException('must not run')],
        ]);

        $this->assertSame('mandatory', $failure->stage);
        $this->assertSame(['mandatory:boundary'], $this->ran);
    }

    // ----- rule identity -----------------------------------------------------

    public function test_duplicate_rule_ids_within_a_stage_are_rejected(): void
    {
        foreach ([
            fn (): RuleSet => RuleSet::define()->grant($this->rule('dup'), $this->rule('dup')),
            fn (): RuleSet => RuleSet::define()->grant($this->rule('dup'))->grant($this->rule('dup')),
            fn (): RuleSet => RuleSet::define()->mandatory($this->rule('dup'), $this->rule('dup')),
            fn (): RuleSet => RuleSet::define()->deny($this->rule('dup'))->deny($this->rule('dup')),
            fn (): RuleSet => RuleSet::define()->privileged($this->rule('dup'), $this->rule('dup')),
        ] as $build) {
            try {
                $build();
                $this->fail('A duplicate rule id was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('dup', $exception->getMessage());
            }
        }
    }

    public function test_the_same_rule_id_may_be_used_in_two_different_stages(): void
    {
        $rules = RuleSet::define()->deny($this->rule('same'))->grant($this->rule('same'));

        $this->assertSame(['same'], array_keys($rules->rulesOf(Stage::Deny)));
        $this->assertSame(['same'], array_keys($rules->rulesOf(Stage::Grant)));
    }

    // ----- helpers -----------------------------------------------------------

    /**
     * @param array<string, array<string, Decision|Throwable>> $stages
     */
    private function reduce(array $stages, Decision $terminal = Decision::Deny): Decision
    {
        return (new StageReducer())->reduce($this->ruleSet($stages, $terminal), $this->evaluator($stages));
    }

    /**
     * @param array<string, array<string, Decision|Throwable>> $stages
     */
    private function reduceExpectingFailure(array $stages, Decision $terminal = Decision::Deny): StageEvaluationFailed
    {
        try {
            $this->reduce($stages, $terminal);
        } catch (StageEvaluationFailed $failure) {
            return $failure;
        }

        $this->fail('The stage did not fail.');
    }

    /**
     * @param array<string, array<string, Decision|Throwable>> $stages
     */
    private function ruleSet(array $stages, Decision $terminal): RuleSet
    {
        $rules = RuleSet::define()->terminal($terminal)->allowAnonymous();

        foreach ($stages as $stage => $outcomes) {
            $made = array_map($this->rule(...), array_keys($outcomes));

            $rules = match (Stage::from($stage)) {
                Stage::Mandatory => $rules->mandatory(...$made),
                Stage::Privileged => $rules->privileged(...$made),
                Stage::Deny => $rules->deny(...$made),
                Stage::Grant => $rules->grant(...$made),
            };
        }

        return $rules;
    }

    /**
     * @param array<string, array<string, Decision|Throwable>> $stages
     * @return callable(PredicateRule|ActionRule, Stage): Decision
     */
    private function evaluator(array $stages): callable
    {
        return function (PredicateRule|ActionRule $rule, Stage $stage) use ($stages): Decision {
            $this->ran[] = $stage->value.':'.$rule->id();

            $outcome = $stages[$stage->value][$rule->id()];

            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        };
    }

    private function rule(string $id): PredicateRule
    {
        return Rule::of($id, static fn (): Predicate => Predicate::always());
    }
}

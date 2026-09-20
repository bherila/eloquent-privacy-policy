<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Policy;

use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingFact;
use BWH\EloquentPrivacyPolicy\Exceptions\StageEvaluationFailed;
use BWH\EloquentPrivacyPolicy\Policy\ActionRule;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\PredicateRule;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Policy\Stage;
use BWH\EloquentPrivacyPolicy\Policy\StageReducer;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\QaSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Question;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support\Rng;
use RuntimeException;
use Throwable;

/**
 * Contract section 2: "For a valid rule set, reordering rules within a stage
 * changes neither the decision nor whether an error is raised nor the set of
 * wrapped errors."
 *
 * Property-style and seeded: every failure message prints the seed that
 * reproduces it exactly.
 */
final class PermutationInvarianceTest extends FixtureTestCase
{
    private const int SEED = 424242;

    private const int CASES = 200;

    private const int PERMUTATIONS = 6;

    public function test_reordering_rules_within_a_stage_never_changes_the_outcome(): void
    {
        $rng = new Rng(self::SEED);

        for ($case = 0; $case < self::CASES; $case++) {
            $stages = $this->randomStages($rng);
            $terminal = $rng->chance(30) ? Decision::Allow : Decision::Deny;

            $baseline = $this->observe($stages, $terminal);

            for ($permutation = 0; $permutation < self::PERMUTATIONS; $permutation++) {
                $shuffled = [];

                foreach ($stages as $stage => $outcomes) {
                    $ids = $rng->shuffled(array_keys($outcomes));
                    $reordered = [];

                    foreach ($ids as $id) {
                        $reordered[$id] = $outcomes[$id];
                    }

                    $shuffled[$stage] = $reordered;
                }

                $this->assertSame($baseline, $this->observe($shuffled, $terminal), sprintf(
                    "permutation changed the outcome\nseed=%d case=%d permutation=%d\nstages=%s",
                    self::SEED,
                    $case,
                    $permutation,
                    $this->render($stages),
                ));
            }
        }
    }

    public function test_the_resolved_read_policy_selects_the_same_rows_under_every_rule_order(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $rng = new Rng(self::SEED + 7);
        $context = $this->context(1, ['is_moderator' => false, 'eligible_organization_ids' => [10]], ['workspace_id' => 1]);

        $baseline = null;

        for ($permutation = 0; $permutation < 12; $permutation++) {
            Privacy::flush();
            Privacy::register(Question::class, $this->shuffledQuestionPolicy($rng));

            $ids = Question::privacyQuery($context)->orderBy('id')->get()->modelKeys();

            $baseline ??= $ids;

            $this->assertSame($baseline, $ids, sprintf(
                'the SQL-resolved policy is order-dependent (seed=%d, permutation=%d)',
                self::SEED + 7,
                $permutation,
            ));
        }

        $this->assertSame(QaSeed::VISIBLE_TO_1, $baseline);
    }

    public function test_the_order_of_failing_rules_does_not_change_the_wrapped_failure_set(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        // No facts at all, so both fact-driven rules fail in their own stage.
        $context = $this->context(1, [], ['workspace_id' => 1]);
        $rng = new Rng(self::SEED + 11);
        $baseline = null;

        for ($permutation = 0; $permutation < 8; $permutation++) {
            Privacy::flush();
            Privacy::register(Question::class, $this->shuffledQuestionPolicy($rng));

            try {
                Question::privacyQuery($context)->get();
                $this->fail('A missing fact did not fail the stage.');
            } catch (StageEvaluationFailed $failure) {
                $observed = [$failure->stage, array_keys($failure->failures)];
                $baseline ??= $observed;

                $this->assertSame($baseline, $observed, 'the wrapped failure set depends on rule order');
            }
        }
    }

    // ----- helpers -----------------------------------------------------------

    /**
     * Decision, error class and wrapped rule ids of one reduction.
     *
     * @param array<string, array<string, Decision|Throwable>> $stages
     * @return array{string, string, list<string>}
     */
    private function observe(array $stages, Decision $terminal): array
    {
        $rules = RuleSet::define()->terminal($terminal)->allowAnonymous();

        foreach ($stages as $stage => $outcomes) {
            $made = array_map(
                static fn (string $id): PredicateRule => Rule::of($id, static fn (): Predicate => Predicate::always()),
                array_keys($outcomes),
            );

            $rules = match (Stage::from($stage)) {
                Stage::Mandatory => $rules->mandatory(...$made),
                Stage::Privileged => $rules->privileged(...$made),
                Stage::Deny => $rules->deny(...$made),
                Stage::Grant => $rules->grant(...$made),
            };
        }

        $evaluate = static function (PredicateRule|ActionRule $rule, Stage $stage) use ($stages): Decision {
            $outcome = $stages[$stage->value][$rule->id()];

            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        };

        try {
            return [(new StageReducer())->reduce($rules, $evaluate)->value, '', []];
        } catch (StageEvaluationFailed $failure) {
            return ['', $failure->stage, array_keys($failure->failures)];
        }
    }

    /** @return array<string, array<string, Decision|Throwable>> */
    private function randomStages(Rng $rng): array
    {
        $stages = [];

        foreach (Stage::cases() as $stage) {
            $count = $rng->between(0, 4);
            $outcomes = [];

            for ($i = 0; $i < $count; $i++) {
                $id = sprintf('r%02d', $rng->between(0, 40));

                if (isset($outcomes[$id])) {
                    continue; // ids are unique within a stage by construction
                }

                $outcomes[$id] = match (true) {
                    $rng->chance(15) => $rng->chance(50)
                        ? new MissingFact('fact for '.$id)
                        : new RuntimeException('boom in '.$id),
                    $rng->chance(45) => $stage->decisive(),
                    default => Decision::Skip,
                };
            }

            if ($outcomes !== []) {
                $stages[$stage->value] = $outcomes;
            }
        }

        return $stages;
    }

    /** @param array<string, array<string, Decision|Throwable>> $stages */
    private function render(array $stages): string
    {
        $parts = [];

        foreach ($stages as $stage => $outcomes) {
            $rendered = [];

            foreach ($outcomes as $id => $outcome) {
                $rendered[] = $id.'='.($outcome instanceof Throwable ? 'throws('.$outcome::class.')' : $outcome->value);
            }

            $parts[] = $stage.'{'.implode(', ', $rendered).'}';
        }

        return implode(' ', $parts);
    }

    /** The Q&A read policy with the rules of every stage in a random order. */
    private function shuffledQuestionPolicy(Rng $rng): ModelPolicy
    {
        $grants = [
            Rule::of('author', fn ($c) => Col::int('author_id')->eq($c->viewer->intId())),
            Rule::of('faq', fn () => Col::bool('is_faq')->eq(true)),
            Rule::of('collaborator', fn ($c) => Exists::in('fx_question_collaborators')
                ->match('id', 'question_id')
                ->where(Col::int('user_id')->eq($c->viewer->intId()))),
            Rule::of('organization', fn ($c) => Col::int('organization_id')->in($c->facts->ints('eligible_organization_ids'))),
        ];

        $mandatory = [
            Rule::of('workspace', fn ($c) => Col::int('workspace_id')->eq($c->scope->int('workspace_id'))),
            Rule::of('not_deleted', fn () => Predicate::always()),
        ];

        $denies = [
            Rule::of('archived', fn () => Col::bool('archived')->eq(true)),
            Rule::of('never_denies', fn () => Predicate::never()),
        ];

        $privileged = [
            Rule::of('moderator', fn ($c) => Predicate::constant($c->facts->bool('is_moderator'))),
        ];

        return ModelPolicy::for(Question::class)->read(
            RuleSet::define()
                ->mandatory(...$rng->shuffled($mandatory))
                ->privileged(...$rng->shuffled($privileged))
                ->deny(...$rng->shuffled($denies))
                ->grant(...$rng->shuffled($grants)),
        );
    }
}

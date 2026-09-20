<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Exceptions\InvalidOutcome;

/**
 * The conservative evaluator: enter a stage, complete and validate all of its
 * rules, reduce, and only then decide whether to enter the next one.
 */
final readonly class StageReducer
{
    public function __construct(private GroupExecutor $executor = new SynchronousGroupExecutor())
    {
    }

    /**
     * @param callable(PredicateRule|ActionRule, Stage): Decision $evaluate
     */
    public function reduce(RuleSet $rules, callable $evaluate): Decision
    {
        foreach (Stage::cases() as $stage) {
            $outcomes = $this->executor->run(
                $stage,
                $rules->rulesOf($stage),
                static function (PredicateRule|ActionRule $rule) use ($evaluate, $stage): Decision {
                    $outcome = $evaluate($rule, $stage);

                    if (! $stage->permits($outcome)) {
                        throw new InvalidOutcome(sprintf(
                            'Rule "%s" returned %s, which the %s stage does not permit.',
                            $rule->id(),
                            $outcome->name,
                            $stage->value,
                        ));
                    }

                    return $outcome;
                },
            );

            if (in_array($stage->decisive(), $outcomes, true)) {
                return $stage->decisive();
            }
        }

        return $rules->terminal;
    }
}

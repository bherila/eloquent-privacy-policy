<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Exceptions\StageEvaluationFailed;

/**
 * Evaluates every rule of one entered stage. The seam where concurrency could
 * be added later; whatever the strategy, all rules complete (or fail) before
 * the stage is reduced.
 */
interface GroupExecutor
{
    /**
     * @template TRule of PredicateRule|ActionRule
     * @template TResult
     *
     * @param array<string, TRule> $rules keyed by rule id
     * @param callable(TRule): TResult $evaluate evaluates and validates one rule
     * @return array<string, TResult> keyed by rule id
     *
     * @throws StageEvaluationFailed when any rule threw, after all rules ran
     */
    public function run(Stage $stage, array $rules, callable $evaluate): array;
}

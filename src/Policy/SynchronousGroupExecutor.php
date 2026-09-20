<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Exceptions\StageEvaluationFailed;
use Throwable;

final class SynchronousGroupExecutor implements GroupExecutor
{
    public function run(Stage $stage, array $rules, callable $evaluate): array
    {
        $results = [];
        $failures = [];

        foreach ($rules as $id => $rule) {
            try {
                $results[$id] = $evaluate($rule);
            } catch (Throwable $failure) {
                $failures[$id] = $failure;
            }
        }

        if ($failures !== []) {
            // Sorted so that neither the message nor "previous" depends on rule order.
            ksort($failures, SORT_STRING);

            throw new StageEvaluationFailed($stage->value, $failures);
        }

        return $results;
    }
}

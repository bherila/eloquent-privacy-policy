<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

use Throwable;

/**
 * One or more rules of an entered stage threw. Every rule of the stage was
 * still evaluated; the failures are ordered by rule id so the result does not
 * depend on rule order.
 */
final class StageEvaluationFailed extends PrivacyException
{
    /** @param array<string, Throwable> $failures keyed by rule id, sorted by key */
    public function __construct(public readonly string $stage, public readonly array $failures)
    {
        $first = $failures[array_key_first($failures)] ?? null;

        parent::__construct(
            sprintf('Stage "%s" failed in rule(s): %s', $stage, implode(', ', array_keys($failures))),
            0,
            $first,
        );
    }
}

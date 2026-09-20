<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;

/**
 * A rule expressed as a predicate, so it can be compiled to SQL and evaluated
 * at runtime. Must be pure: same context, same predicate.
 */
interface PredicateRule
{
    /** Stable and unique within its stage. */
    public function id(): string;

    public function predicate(PrivacyContext $context): Predicate;
}

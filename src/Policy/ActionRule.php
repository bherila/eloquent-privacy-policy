<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;

/**
 * Runtime-only rule for an action. May be arbitrary pure PHP; it is never used
 * to filter a collection.
 */
interface ActionRule
{
    /** Stable and unique within its stage. */
    public function id(): string;

    public function decide(PrivacyContext $context, ActionInput $input): Decision;
}

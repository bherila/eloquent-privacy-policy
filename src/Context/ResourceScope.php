<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

/** Validated resource-boundary keys for the operation, e.g. ['tenant_id' => 7]. */
final readonly class ResourceScope extends ScalarBag
{
    protected function label(): string
    {
        return 'Resource scope key';
    }
}

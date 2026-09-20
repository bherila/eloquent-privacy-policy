<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

/** Facts resolved by a trusted adapter for one operation. Never cached across operations. */
final readonly class Facts extends ScalarBag
{
    protected function label(): string
    {
        return 'Fact';
    }
}

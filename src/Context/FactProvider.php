<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

/**
 * Seam for resolving the facts of one operation. Implementations are trusted
 * application adapters; results are operation-local.
 */
interface FactProvider
{
    public function facts(PrivacyContext $context): Facts;
}

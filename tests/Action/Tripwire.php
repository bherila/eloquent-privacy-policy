<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

/** A rule that must never run carries one of these. */
final class Tripwire
{
    public bool $ran = false;

    public function trip(): void
    {
        $this->ran = true;
    }
}

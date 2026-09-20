<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

/** The capacity a viewer acts in (for example on behalf of an organisation). Not an identity. */
final readonly class Capacity
{
    public function __construct(
        public string $kind,
        public int|string $id,
    ) {
    }
}

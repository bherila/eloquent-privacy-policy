<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

use DateTimeImmutable;

/** Column against a bound value. False when the column is NULL. */
final readonly class Comparison extends Predicate
{
    public function __construct(
        public Col $column,
        public Op $op,
        public int|string|bool|DateTimeImmutable $value,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

/** Two columns of the same row are equal. False when either is NULL. */
final readonly class ColumnComparison extends Predicate
{
    public function __construct(public Col $left, public Col $right)
    {
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

final readonly class NullCheck extends Predicate
{
    public function __construct(public Col $column, public bool $expectNull)
    {
    }
}

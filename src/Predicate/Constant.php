<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

final readonly class Constant extends Predicate
{
    public function __construct(public bool $value)
    {
    }
}

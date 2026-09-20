<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

/** Built through Predicate::not(). Well defined because every node is two-valued. */
final readonly class Negation extends Predicate
{
    public function __construct(public Predicate $inner)
    {
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

final readonly class AllOf extends Predicate
{
    /** @param non-empty-list<Predicate> $predicates */
    public function __construct(public array $predicates)
    {
    }
}

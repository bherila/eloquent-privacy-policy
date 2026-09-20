<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

use DateTimeImmutable;

/** Column is one of a non-empty list of bound values. False when the column is NULL. */
final readonly class InList extends Predicate
{
    /** @param non-empty-list<int|string|bool|DateTimeImmutable> $values */
    public function __construct(public Col $column, public array $values)
    {
    }
}

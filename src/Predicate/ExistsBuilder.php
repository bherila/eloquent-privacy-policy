<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

final readonly class ExistsBuilder
{
    /** @param list<array{Col, Col}> $matches */
    public function __construct(private string $table, private array $matches = [])
    {
    }

    /** Correlate on integer keys. */
    public function match(string $outerColumn, string $innerColumn): self
    {
        return $this->matchCols(Col::int($outerColumn), Col::int($innerColumn));
    }

    public function matchCols(Col $outer, Col $inner): self
    {
        return new self($this->table, [...$this->matches, [$outer, $inner]]);
    }

    public function where(?Predicate $predicate = null): Predicate
    {
        return Exists::build($this->table, $this->matches, $predicate ?? Predicate::always());
    }
}

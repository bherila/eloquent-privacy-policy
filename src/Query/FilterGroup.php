<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Query;

use BWH\EloquentPrivacyPolicy\Exceptions\InvalidIdentifier;
use BWH\EloquentPrivacyPolicy\Exceptions\UnsupportedProtectedOperation;
use BWH\EloquentPrivacyPolicy\Predicate\Identifier;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;

/**
 * Caller-supplied filters. Recorded, validated, and only ever replayed inside
 * their own nested group, so an OR here cannot widen the protected result.
 * Callers never touch a query builder.
 */
final class FilterGroup
{
    private const array OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    /** @var list<array{string, string, mixed, mixed, mixed}> [kind, boolean, a, b, c] */
    private array $filters = [];

    public function __construct(private readonly string $table)
    {
    }

    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere('and', $column, $operator, $value, func_num_args());
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere('or', $column, $operator, $value, func_num_args());
    }

    /** @param iterable<mixed> $values */
    public function whereIn(string $column, iterable $values): static
    {
        return $this->add('in', 'and', $this->column($column), $this->values($values), false);
    }

    /** @param iterable<mixed> $values */
    public function orWhereIn(string $column, iterable $values): static
    {
        return $this->add('in', 'or', $this->column($column), $this->values($values), false);
    }

    /** @param iterable<mixed> $values */
    public function whereNotIn(string $column, iterable $values): static
    {
        return $this->add('in', 'and', $this->column($column), $this->values($values), true);
    }

    public function whereNull(string $column): static
    {
        return $this->add('null', 'and', $this->column($column), false, null);
    }

    public function orWhereNull(string $column): static
    {
        return $this->add('null', 'or', $this->column($column), false, null);
    }

    public function whereNotNull(string $column): static
    {
        return $this->add('null', 'and', $this->column($column), true, null);
    }

    public function orWhereNotNull(string $column): static
    {
        return $this->add('null', 'or', $this->column($column), true, null);
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        return $this->add('between', 'and', $this->column($column), [$this->value($from), $this->value($to)], null);
    }

    public function isEmpty(): bool
    {
        return $this->filters === [];
    }

    /** @internal replayed by the protected builder inside one nested group */
    public function applyTo(Builder $group): void
    {
        foreach ($this->filters as [$kind, $boolean, $a, $b, $c]) {
            match ($kind) {
                'where' => $group->where($a, $b, $c, $boolean),
                'in' => $group->whereIn($a, $b, $boolean, $c),
                'null' => $group->whereNull($a, $boolean, $b),
                'between' => $group->whereBetween($a, $b, $boolean),
                'nested' => $group->where(static fn (Builder $nested) => $a->applyTo($nested), null, null, $boolean),
                default => throw new UnsupportedProtectedOperation('Unknown filter kind.'),
            };
        }
    }

    private function addWhere(string $boolean, string|Closure $column, mixed $operator, mixed $value, int $arguments): static
    {
        if ($column instanceof Closure) {
            $nested = new self($this->table);
            $column($nested);

            // An empty group would be dropped by the query builder; nothing to add.
            return $nested->isEmpty() ? $this : $this->add('nested', $boolean, $nested, null, null);
        }

        if ($arguments === 2) {
            [$operator, $value] = ['=', $operator];
        }

        if (! is_string($operator) || ! in_array(strtolower($operator), self::OPERATORS, true)) {
            throw new InvalidIdentifier('Unsupported filter operator.');
        }

        if ($value === null) {
            throw new UnsupportedProtectedOperation('Compare against NULL with whereNull() / whereNotNull().');
        }

        return $this->add('where', $boolean, $this->column($column), strtolower($operator), $this->value($value));
    }

    private function add(string $kind, string $boolean, mixed $a, mixed $b, mixed $c): static
    {
        $this->filters[] = [$kind, $boolean, $a, $b, $c];

        return $this;
    }

    /** Accepts "column" or "<root table>.column"; always returns the qualified form. */
    private function column(string $column): string
    {
        return Identifier::column($column, $this->table);
    }

    private function value(mixed $value): int|string|bool|float|DateTimeInterface
    {
        if (is_scalar($value) || $value instanceof DateTimeInterface) {
            return $value;
        }

        throw new UnsupportedProtectedOperation(sprintf(
            'Filter values must be scalars or dates; %s given. Expressions, closures and subqueries are not accepted.',
            get_debug_type($value),
        ));
    }

    /**
     * @param iterable<mixed> $values
     * @return list<int|string|bool|float|DateTimeInterface>
     */
    private function values(iterable $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            $clean[] = $this->value($value);
        }

        return $clean;
    }
}

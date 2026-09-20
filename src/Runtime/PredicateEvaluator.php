<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Runtime;

use BWH\EloquentPrivacyPolicy\Exceptions\UncompilablePolicy;
use BWH\EloquentPrivacyPolicy\Predicate\AllOf;
use BWH\EloquentPrivacyPolicy\Predicate\AnyOf;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\ColumnComparison;
use BWH\EloquentPrivacyPolicy\Predicate\Comparison;
use BWH\EloquentPrivacyPolicy\Predicate\Constant;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\InList;
use BWH\EloquentPrivacyPolicy\Predicate\Negation;
use BWH\EloquentPrivacyPolicy\Predicate\NullCheck;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use DateTimeImmutable;

/**
 * The PHP interpreter of the predicate IR. Two-valued, reads raw attributes,
 * and evaluates every child of a conjunction/disjunction so that whether an
 * error is raised does not depend on child order.
 */
final class PredicateEvaluator
{
    public function evaluate(Predicate $predicate, RowSnapshot $row, ExistsFacts $facts): bool
    {
        return match (true) {
            $predicate instanceof Constant => $predicate->value,
            $predicate instanceof Comparison => $this->comparison($predicate, $row),
            $predicate instanceof InList => $this->inList($predicate, $row),
            $predicate instanceof NullCheck => ($row->get($predicate->column->name) === null) === $predicate->expectNull,
            $predicate instanceof ColumnComparison => $this->columns($predicate, $row),
            $predicate instanceof AllOf => ! in_array(false, $this->each($predicate->predicates, $row, $facts), true),
            $predicate instanceof AnyOf => in_array(true, $this->each($predicate->predicates, $row, $facts), true),
            $predicate instanceof Negation => ! $this->evaluate($predicate->inner, $row, $facts),
            $predicate instanceof Exists => $this->exists($predicate, $row, $facts),
            default => throw new UncompilablePolicy(sprintf(
                '%s must be expanded by the resolver before it is evaluated.',
                $predicate::class,
            )),
        };
    }

    /**
     * Key of the outer row for an Exists node, or null when any match column is
     * NULL (a NULL key matches nothing, as in SQL).
     */
    public function outerTuple(Exists $node, RowSnapshot $row): ?string
    {
        $parts = [];

        foreach ($node->matches as [$outer]) {
            $value = $this->value($outer, $row);

            if ($value === null) {
                return null;
            }

            $parts[] = self::tuplePart($outer, $value);
        }

        return implode("\x1f", $parts);
    }

    /** Key of an inner row; same encoding as outerTuple(). */
    public function innerTuple(Exists $node, RowSnapshot $inner): ?string
    {
        $parts = [];

        foreach ($node->matches as [, $innerColumn]) {
            $value = $this->value($innerColumn, $inner);

            if ($value === null) {
                return null;
            }

            $parts[] = self::tuplePart($innerColumn, $value);
        }

        return implode("\x1f", $parts);
    }

    public function value(Col $column, RowSnapshot $row): int|string|bool|DateTimeImmutable|null
    {
        $raw = $row->get($column->name);

        return $raw === null ? null : $column->type->rawValue($raw, $column->name);
    }

    private function comparison(Comparison $predicate, RowSnapshot $row): bool
    {
        $value = $this->value($predicate->column, $row);

        return $value !== null
            && $predicate->op->holds($predicate->column->type->compare($value, $predicate->value));
    }

    private function inList(InList $predicate, RowSnapshot $row): bool
    {
        $value = $this->value($predicate->column, $row);

        if ($value === null) {
            return false;
        }

        foreach ($predicate->values as $candidate) {
            if ($predicate->column->type->compare($value, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }

    private function columns(ColumnComparison $predicate, RowSnapshot $row): bool
    {
        $left = $this->value($predicate->left, $row);
        $right = $this->value($predicate->right, $row);

        return $left !== null && $right !== null && $predicate->left->type->compare($left, $right) === 0;
    }

    private function exists(Exists $predicate, RowSnapshot $row, ExistsFacts $facts): bool
    {
        $tuple = $this->outerTuple($predicate, $row);

        // Still consult the facts when the key is NULL, so an unprepared node is always an error.
        return $facts->holds($predicate, $tuple ?? "\x00null") && $tuple !== null;
    }

    /**
     * @param non-empty-list<Predicate> $predicates
     * @return list<bool>
     */
    private function each(array $predicates, RowSnapshot $row, ExistsFacts $facts): array
    {
        return array_map(fn (Predicate $child): bool => $this->evaluate($child, $row, $facts), $predicates);
    }

    private static function tuplePart(Col $column, int|string|bool|DateTimeImmutable $value): string
    {
        return (string) $column->type->binding($value);
    }
}

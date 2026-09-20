<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Sql;

use BWH\EloquentPrivacyPolicy\Exceptions\UncompilablePolicy;
use BWH\EloquentPrivacyPolicy\Predicate\AllOf;
use BWH\EloquentPrivacyPolicy\Predicate\AnyOf;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\ColType;
use BWH\EloquentPrivacyPolicy\Predicate\ColumnComparison;
use BWH\EloquentPrivacyPolicy\Predicate\Comparison;
use BWH\EloquentPrivacyPolicy\Predicate\Constant;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Identifier;
use BWH\EloquentPrivacyPolicy\Predicate\InList;
use BWH\EloquentPrivacyPolicy\Predicate\Negation;
use BWH\EloquentPrivacyPolicy\Predicate\NullCheck;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * The SQL interpreter of the predicate IR. Every node compiles to a two-valued
 * expression: a comparison is always guarded by IS NOT NULL, so NOT means the
 * same thing here as in the runtime evaluator. Identifiers are validated and
 * wrapped by the grammar; values are always bound.
 */
final class PredicateCompiler
{
    /**
     * Add $predicate to $query as ONE nested group, ANDed with whatever the
     * query already has.
     *
     * @param string $qualifier table name or alias the predicate's columns belong to
     */
    public function apply(Builder $query, Predicate $predicate, string $qualifier): void
    {
        Identifier::assert($qualifier);

        $query->where(function (Builder $group) use ($predicate, $qualifier): void {
            $this->compile($group, $predicate, $qualifier, 0);
        });
    }

    private function compile(Builder $query, Predicate $predicate, string $qualifier, int $depth): void
    {
        match (true) {
            $predicate instanceof Constant => $query->whereRaw($predicate->value ? '1 = 1' : '0 = 1'),
            $predicate instanceof Comparison => $this->comparison($query, $predicate, $qualifier),
            $predicate instanceof InList => $this->inList($query, $predicate, $qualifier),
            $predicate instanceof NullCheck => $predicate->expectNull
                ? $query->whereNull($this->column($predicate->column, $qualifier))
                : $query->whereNotNull($this->column($predicate->column, $qualifier)),
            $predicate instanceof ColumnComparison => $this->columns($query, $predicate, $qualifier),
            $predicate instanceof AllOf => $query->where(function (Builder $group) use ($predicate, $qualifier, $depth): void {
                foreach ($predicate->predicates as $child) {
                    $group->where(fn (Builder $one) => $this->compile($one, $child, $qualifier, $depth));
                }
            }),
            $predicate instanceof AnyOf => $query->where(function (Builder $group) use ($predicate, $qualifier, $depth): void {
                foreach ($predicate->predicates as $child) {
                    $group->orWhere(fn (Builder $one) => $this->compile($one, $child, $qualifier, $depth));
                }
            }),
            $predicate instanceof Negation => $query->whereNot(
                fn (Builder $group) => $this->compile($group, $predicate->inner, $qualifier, $depth),
            ),
            $predicate instanceof Exists => $this->exists($query, $predicate, $qualifier, $depth),
            default => throw new UncompilablePolicy(sprintf(
                '%s must be expanded by the resolver before it is compiled.',
                $predicate::class,
            )),
        };
    }

    private function comparison(Builder $query, Comparison $predicate, string $qualifier): void
    {
        $column = $this->column($predicate->column, $qualifier);
        $binding = $predicate->column->type->binding($predicate->value);

        if ($this->storesDatetimeAsText($query, $predicate->column)) {
            $this->textDatetime($query, $column, $predicate->op->value.' ?', [$binding.'.000']);

            return;
        }

        $query->where(function (Builder $group) use ($query, $predicate, $column, $binding): void {
            $group->whereNotNull($column)->where($column, $predicate->op->value, $binding);

            if ($this->needsExactStringMatch($query, $predicate->column)) {
                $group->whereRaw(
                    sprintf('CAST(%s AS BINARY) = CAST(? AS BINARY)', $query->getGrammar()->wrap($column)),
                    [$binding],
                );
            }
        });
    }

    private function inList(Builder $query, InList $predicate, string $qualifier): void
    {
        $column = $this->column($predicate->column, $qualifier);
        $bindings = array_map($predicate->column->type->binding(...), $predicate->values);

        if ($this->storesDatetimeAsText($query, $predicate->column)) {
            $this->textDatetime(
                $query,
                $column,
                'IN ('.implode(', ', array_fill(0, count($bindings), '?')).')',
                array_map(static fn (int|string $binding): string => $binding.'.000', $bindings),
            );

            return;
        }

        $query->where(function (Builder $group) use ($query, $predicate, $column, $bindings): void {
            $group->whereNotNull($column)->whereIn($column, $bindings);

            if ($this->needsExactStringMatch($query, $predicate->column)) {
                $group->whereRaw(
                    sprintf(
                        'CAST(%s AS BINARY) IN (%s)',
                        $query->getGrammar()->wrap($column),
                        implode(', ', array_fill(0, count($bindings), 'CAST(? AS BINARY)')),
                    ),
                    $bindings,
                );
            }
        });
    }

    private function columns(Builder $query, ColumnComparison $predicate, string $qualifier): void
    {
        $left = $this->column($predicate->left, $qualifier);
        $right = $this->column($predicate->right, $qualifier);

        if ($this->storesDatetimeAsText($query, $predicate->left)) {
            $this->textDatetime($query, $left, '= '.$this->normalisedDatetime($query, $right), [], $right);

            return;
        }

        $query->where(function (Builder $group) use ($query, $predicate, $left, $right): void {
            $group->whereNotNull($left)->whereNotNull($right)->whereColumn($left, '=', $right);

            if ($this->needsExactStringMatch($query, $predicate->left)) {
                $group->whereRaw(sprintf(
                    'CAST(%s AS BINARY) = CAST(%s AS BINARY)',
                    $query->getGrammar()->wrap($left),
                    $query->getGrammar()->wrap($right),
                ));
            }
        });
    }

    private function exists(Builder $query, Exists $predicate, string $outerQualifier, int $depth): void
    {
        // Aliased, so an EXISTS over the outer table itself stays unambiguous.
        $alias = 'pp_'.($depth + 1);

        $query->whereExists(function (Builder $sub) use ($predicate, $outerQualifier, $alias, $depth): void {
            $sub->selectRaw('1')->from($predicate->table, $alias);

            foreach ($predicate->matches as [$outer, $inner]) {
                $outerColumn = $this->column($outer, $outerQualifier);
                $innerColumn = $this->column($inner, $alias);

                // Top-level conjuncts of the subquery: an unknown comparison just fails to match.
                $sub->whereColumn($innerColumn, '=', $outerColumn);

                if ($this->needsExactStringMatch($sub, $outer)) {
                    $sub->whereRaw(sprintf(
                        'CAST(%s AS BINARY) = CAST(%s AS BINARY)',
                        $sub->getGrammar()->wrap($innerColumn),
                        $sub->getGrammar()->wrap($outerColumn),
                    ));
                }
            }

            $sub->where(fn (Builder $group) => $this->compile($group, $predicate->where, $alias, $depth + 1));
        });
    }

    /**
     * SQLite keeps datetimes as text, so '12:00:00' and '12:00:00.000' are
     * different values to a plain comparison while they are the same instant to
     * every other engine and to the runtime evaluator. Compare normalised text
     * instead (millisecond precision, which is all strftime offers). A value
     * SQLite cannot parse normalises to NULL and is guarded like any other NULL.
     *
     * @param list<string> $bindings
     */
    private function textDatetime(Builder $query, string $column, string $comparison, array $bindings, ?string $other = null): void
    {
        $query->where(function (Builder $group) use ($query, $column, $comparison, $bindings, $other): void {
            $group->whereRaw($this->normalisedDatetime($query, $column).' IS NOT NULL');

            if ($other !== null) {
                $group->whereRaw($this->normalisedDatetime($query, $other).' IS NOT NULL');
            }

            $group->whereRaw($this->normalisedDatetime($query, $column).' '.$comparison, $bindings);
        });
    }

    private function normalisedDatetime(Builder $query, string $column): string
    {
        return sprintf("strftime('%%Y-%%m-%%d %%H:%%M:%%f', %s)", $query->getGrammar()->wrap($column));
    }

    private function storesDatetimeAsText(Builder $query, Col $column): bool
    {
        return $column->type === ColType::Datetime && $this->driver($query) === 'sqlite';
    }

    private function driver(Builder $query): string
    {
        $connection = $query->getConnection();

        return $connection instanceof Connection ? $connection->getDriverName() : '';
    }

    private function column(Col $column, string $qualifier): string
    {
        return $qualifier.'.'.Identifier::assert($column->name);
    }

    /**
     * MySQL and MariaDB default to case-insensitive, pad-space collations. The
     * plain comparison stays (it can use an index); the binary comparison makes
     * the match exact, as it already is on SQLite and in PHP.
     */
    private function needsExactStringMatch(Builder $query, Col $column): bool
    {
        return $column->type === ColType::String
            && in_array($this->driver($query), ['mysql', 'mariadb'], true);
    }
}

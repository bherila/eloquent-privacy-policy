<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

use BWH\EloquentPrivacyPolicy\Exceptions\UncompilablePolicy;
use InvalidArgumentException;

/**
 * Constrained correlated EXISTS: a row of $table exists whose match columns
 * equal the outer row's and which satisfies $where (a predicate over $table).
 */
final readonly class Exists extends Predicate
{
    /**
     * @param non-empty-list<array{Col, Col}> $matches pairs of [outer column, inner column]
     */
    private function __construct(
        public string $table,
        public array $matches,
        public Predicate $where,
    ) {
    }

    public static function in(string $table): ExistsBuilder
    {
        return new ExistsBuilder(Identifier::assert($table));
    }

    /**
     * @internal use Exists::in()
     *
     * @param list<array{Col, Col}> $matches
     */
    public static function build(string $table, array $matches, Predicate $where): Predicate
    {
        if ($matches === []) {
            throw new InvalidArgumentException('An EXISTS predicate must be correlated by at least one match().');
        }

        foreach ($matches as [$outer, $inner]) {
            // Integer keys only. A string key cannot be kept exact everywhere:
            // MariaDB's subquery cache keys a correlated column by its own
            // collation, so 'Abc' would inherit the answer computed for 'abc'
            // whatever the subquery itself compares.
            if ($outer->type !== ColType::Int || $inner->type !== ColType::Int) {
                throw new UncompilablePolicy(sprintf(
                    'EXISTS over "%s" is correlated on a %s key; only integer keys are supported.',
                    $table,
                    $outer->type !== ColType::Int ? $outer->type->value : $inner->type->value,
                ));
            }
        }

        // A correlated EXISTS whose inner predicate can never hold is never true.
        return $where->isNever() ? Predicate::never() : new self($table, $matches, $where);
    }
}

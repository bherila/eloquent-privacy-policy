<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support;

use BWH\EloquentPrivacyPolicy\Predicate\AllOf;
use BWH\EloquentPrivacyPolicy\Predicate\AnyOf;
use BWH\EloquentPrivacyPolicy\Predicate\ColumnComparison;
use BWH\EloquentPrivacyPolicy\Predicate\Comparison;
use BWH\EloquentPrivacyPolicy\Predicate\Constant;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\InList;
use BWH\EloquentPrivacyPolicy\Predicate\Negation;
use BWH\EloquentPrivacyPolicy\Predicate\NullCheck;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use DateTimeImmutable;

/** Renders a predicate tree so a property failure can be reproduced by hand. */
final class PredicateDescriber
{
    public static function describe(Predicate $predicate): string
    {
        return match (true) {
            $predicate instanceof Constant => $predicate->value ? 'always()' : 'never()',
            $predicate instanceof Comparison => sprintf(
                '%s:%s %s %s',
                $predicate->column->name,
                $predicate->column->type->value,
                $predicate->op->value,
                self::value($predicate->value),
            ),
            $predicate instanceof InList => sprintf(
                '%s:%s in [%s]',
                $predicate->column->name,
                $predicate->column->type->value,
                implode(', ', array_map(self::value(...), $predicate->values)),
            ),
            $predicate instanceof NullCheck => sprintf(
                '%s IS %sNULL',
                $predicate->column->name,
                $predicate->expectNull ? '' : 'NOT ',
            ),
            $predicate instanceof ColumnComparison => sprintf(
                '%s = %s (%s)',
                $predicate->left->name,
                $predicate->right->name,
                $predicate->left->type->value,
            ),
            $predicate instanceof AllOf => '('.implode(' AND ', array_map(self::describe(...), $predicate->predicates)).')',
            $predicate instanceof AnyOf => '('.implode(' OR ', array_map(self::describe(...), $predicate->predicates)).')',
            $predicate instanceof Negation => 'NOT '.self::describe($predicate->inner),
            $predicate instanceof Exists => sprintf(
                'EXISTS %s ON [%s] WHERE %s',
                $predicate->table,
                implode(', ', array_map(
                    static fn (array $pair): string => $pair[0]->name.'='.$pair[1]->name,
                    $predicate->matches,
                )),
                self::describe($predicate->where),
            ),
            $predicate instanceof ViaParent => 'VIA_PARENT '.$predicate->parent,
            default => $predicate::class,
        };
    }

    private static function value(int|string|bool|DateTimeImmutable $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            $value instanceof DateTimeImmutable => $value->format('Y-m-d H:i:s.u'),
            default => "'".$value."'",
        };
    }
}

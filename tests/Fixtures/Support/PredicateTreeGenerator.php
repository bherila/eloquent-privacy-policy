<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support;

use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds random but reproducible predicate trees over the fx_matrix fixture.
 *
 * Levels: 0 = fx_matrix, 1 = fx_matrix_child, 2 = fx_matrix_grandchild. An
 * EXISTS at level 0 correlates fx_matrix to fx_matrix_child (on an integer or
 * on a string key), an EXISTS at level 1 correlates the child to the
 * grandchild. Nothing below level 2 is generated.
 */
final class PredicateTreeGenerator
{
    /** @var array<int, array<string, list<string>>> level => declared type => columns */
    private const array COLUMNS = [
        0 => [
            'int' => ['c_int', 'c_int2'],
            'string' => ['c_str', 'c_str2'],
            'bool' => ['c_bool'],
            'datetime' => ['c_dt', 'c_dt2'],
        ],
        1 => [
            'int' => ['c_int', 'm_id'],
            'string' => ['c_str', 'k_str'],
            'bool' => ['c_bool'],
            'datetime' => ['c_dt'],
        ],
        2 => [
            'int' => ['c_int', 'child_id'],
            'string' => ['c_str'],
            'bool' => [],
            'datetime' => [],
        ],
    ];

    /** @var non-empty-list<int> */
    private const array INT_VALUES = [-7, -1, 0, 1, 2, 3, 7, 999];

    /** @var non-empty-list<string> */
    private const array STRING_VALUES = ['abc', 'Abc', 'ABC', 'abc ', '', 'zzz', '0'];

    /** @var non-empty-list<string> */
    private const array DATETIME_VALUES = [
        '2026-05-01 00:00:00',
        '2026-06-01 12:00:00',
        '2026-07-01 00:00:00',
    ];

    /**
     * @param bool $fractional use the DATETIME(6) column at level 0 instead of
     *                         the whole-second ones, so a fractional-second
     *                         failure is never mixed in with the others
     */
    public function __construct(
        private readonly Rng $rng,
        private readonly bool $fractional = false,
    ) {
    }

    public function tree(int $budget = 3): Predicate
    {
        return $this->node(0, $budget);
    }

    private function node(int $level, int $budget): Predicate
    {
        if ($budget <= 0) {
            return $this->leaf($level);
        }

        $choice = $this->rng->below(100);

        return match (true) {
            $choice < 22 => Predicate::all(...$this->children($level, $budget)),
            $choice < 44 => Predicate::any(...$this->children($level, $budget)),
            $choice < 58 => Predicate::not($this->node($level, $budget - 1)),
            $choice < 76 && $level < 2 => $this->exists($level, $budget),
            default => $this->leaf($level),
        };
    }

    /** @return non-empty-list<Predicate> */
    private function children(int $level, int $budget): array
    {
        $children = [$this->node($level, $budget - 1), $this->node($level, $budget - 1)];

        if ($this->rng->chance(40)) {
            $children[] = $this->node($level, $budget - 1);
        }

        return $children;
    }

    /**
     * Section 4.1: an EXISTS is correlated on integer keys only. The inner
     * predicate still ranges over every declared type, and an EXISTS is
     * generated under any() and not() as often as anywhere else, so the
     * string-comparison-inside-a-dependent-subquery position stays covered.
     */
    private function exists(int $level, int $budget): Predicate
    {
        $builder = $level === 0
            ? Exists::in('fx_matrix_child')->match('id', 'm_id')
            : Exists::in('fx_matrix_grandchild')->match('id', 'child_id');

        return $builder->where($this->node($level + 1, $budget - 1));
    }

    /**
     * An int-keyed EXISTS whose inner predicate compares a string column: the
     * shape a collation surprise would hide in. Public so the parity suite can
     * place it explicitly under any() and not() as well as letting the random
     * sweep find it.
     */
    public function stringInsideExists(string $value): Predicate
    {
        return Exists::in('fx_matrix_child')
            ->match('id', 'm_id')
            ->where(Col::string('k_str')->eq($value));
    }

    private function leaf(int $level): Predicate
    {
        $type = $this->pickType($level);
        $column = $this->column($level, $type);
        $choice = $this->rng->below(100);

        return match (true) {
            $choice < 4 => Predicate::always(),
            $choice < 8 => Predicate::never(),
            $choice < 14 => $column->isNull(),
            $choice < 20 => $column->isNotNull(),
            $choice < 26 => $this->eqCol($level, $type, $column),
            $choice < 32 => $column->in([]),
            $choice < 48 => $column->in($this->values($type, $this->rng->between(1, 3))),
            $choice < 74 => $column->eq($this->values($type, 1)[0]),
            $this->ordered($type) => $this->orderedComparison($column, $type),
            default => $column->eq($this->values($type, 1)[0]),
        };
    }

    private function eqCol(int $level, string $type, Col $column): Predicate
    {
        $candidates = self::COLUMNS[$level][$type];

        if (count($candidates) < 2) {
            return $column->isNotNull();
        }

        $other = $candidates[1] === $column->name ? $candidates[0] : $candidates[1];

        return $column->eqCol($this->make($type, $other));
    }

    private function orderedComparison(Col $column, string $type): Predicate
    {
        $value = $this->values($type, 1)[0];

        return match ($this->rng->below(4)) {
            0 => $column->lt($value),
            1 => $column->lte($value),
            2 => $column->gt($value),
            default => $column->gte($value),
        };
    }

    private function pickType(int $level): string
    {
        $available = [];

        foreach (self::COLUMNS[$level] as $type => $columns) {
            if ($columns !== []) {
                $available[] = $type;
            }
        }

        return $this->rng->pick($available);
    }

    private function column(int $level, string $type): Col
    {
        if ($level === 0 && $type === 'datetime' && $this->fractional) {
            return Col::datetime('c_dtf');
        }

        $candidates = self::COLUMNS[$level][$type];

        return $this->make($type, $candidates === [] ? 'c_int' : $this->rng->pick($candidates));
    }

    private function make(string $type, string $name): Col
    {
        return match ($type) {
            'int' => Col::int($name),
            'string' => Col::string($name),
            'bool' => Col::bool($name),
            default => Col::datetime($name),
        };
    }

    private function ordered(string $type): bool
    {
        return $type === 'int' || $type === 'datetime';
    }

    /** @return non-empty-list<int|string|bool|DateTimeImmutable> */
    private function values(string $type, int $count): array
    {
        $values = [];

        for ($i = 0; $i < max(1, $count); $i++) {
            $values[] = match ($type) {
                'int' => $this->rng->pick(self::INT_VALUES),
                'string' => $this->rng->pick(self::STRING_VALUES),
                'bool' => $this->rng->chance(50),
                default => new DateTimeImmutable($this->rng->pick(self::DATETIME_VALUES), new DateTimeZone('UTC')),
            };
        }

        return $values;
    }
}

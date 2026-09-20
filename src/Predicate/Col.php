<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

use BWH\EloquentPrivacyPolicy\Exceptions\AttributeTypeMismatch;

/** A typed reference to a column of the table the predicate is evaluated over. */
final readonly class Col
{
    private function __construct(public string $name, public ColType $type)
    {
        Identifier::assert($name);
    }

    public static function int(string $name): self
    {
        return new self($name, ColType::Int);
    }

    public static function string(string $name): self
    {
        return new self($name, ColType::String);
    }

    public static function bool(string $name): self
    {
        return new self($name, ColType::Bool);
    }

    public static function datetime(string $name): self
    {
        return new self($name, ColType::Datetime);
    }

    public function eq(mixed $value): Predicate
    {
        return new Comparison($this, Op::Eq, $this->type->policyValue($value, $this->name));
    }

    public function lt(mixed $value): Predicate
    {
        return $this->ordered(Op::Lt, $value);
    }

    public function lte(mixed $value): Predicate
    {
        return $this->ordered(Op::Lte, $value);
    }

    public function gt(mixed $value): Predicate
    {
        return $this->ordered(Op::Gt, $value);
    }

    public function gte(mixed $value): Predicate
    {
        return $this->ordered(Op::Gte, $value);
    }

    /** @param iterable<mixed> $values */
    public function in(iterable $values): Predicate
    {
        $normalised = [];

        foreach ($values as $value) {
            $normalised[] = $this->type->policyValue($value, $this->name);
        }

        return $normalised === [] ? Predicate::never() : new InList($this, $normalised);
    }

    public function isNull(): Predicate
    {
        return new NullCheck($this, true);
    }

    public function isNotNull(): Predicate
    {
        return new NullCheck($this, false);
    }

    public function eqCol(self $other): Predicate
    {
        if ($other->type !== $this->type) {
            throw new AttributeTypeMismatch(sprintf(
                'Columns "%s" (%s) and "%s" (%s) have different declared types.',
                $this->name,
                $this->type->value,
                $other->name,
                $other->type->value,
            ));
        }

        return new ColumnComparison($this, $other);
    }

    private function ordered(Op $op, mixed $value): Predicate
    {
        if (! $this->type->supportsOrdering()) {
            throw new AttributeTypeMismatch(sprintf(
                'Column "%s" is declared %s, which has no ordered comparison.',
                $this->name,
                $this->type->value,
            ));
        }

        return new Comparison($this, $op, $this->type->policyValue($value, $this->name));
    }
}

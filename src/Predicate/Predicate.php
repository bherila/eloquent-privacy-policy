<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

/**
 * Immutable predicate over the columns of one table. Every node is two-valued:
 * it is true or false for a row, never unknown. See docs/contract.md section 4.
 */
abstract readonly class Predicate
{
    public static function always(): self
    {
        return new Constant(true);
    }

    public static function never(): self
    {
        return new Constant(false);
    }

    public static function constant(bool $value): self
    {
        return new Constant($value);
    }

    public static function all(self ...$predicates): self
    {
        $kept = [];

        foreach ($predicates as $predicate) {
            if ($predicate instanceof Constant) {
                if (! $predicate->value) {
                    return self::never();
                }

                continue;
            }

            $kept[] = $predicate;
        }

        return match (count($kept)) {
            0 => self::always(),
            1 => $kept[0],
            default => new AllOf($kept),
        };
    }

    public static function any(self ...$predicates): self
    {
        $kept = [];

        foreach ($predicates as $predicate) {
            if ($predicate instanceof Constant) {
                if ($predicate->value) {
                    return self::always();
                }

                continue;
            }

            $kept[] = $predicate;
        }

        return match (count($kept)) {
            0 => self::never(),
            1 => $kept[0],
            default => new AnyOf($kept),
        };
    }

    public static function not(self $predicate): self
    {
        return match (true) {
            $predicate instanceof Constant => new Constant(! $predicate->value),
            $predicate instanceof Negation => $predicate->inner,
            default => new Negation($predicate),
        };
    }

    public function isAlways(): bool
    {
        return $this instanceof Constant && $this->value;
    }

    public function isNever(): bool
    {
        return $this instanceof Constant && ! $this->value;
    }
}

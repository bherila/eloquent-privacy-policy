<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

use BWH\EloquentPrivacyPolicy\Exceptions\AttributeTypeMismatch;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Declared column types. Each type fixes how a policy value is bound and how a
 * raw attribute is normalised, so that SQL and runtime comparisons agree.
 */
enum ColType: string
{
    case Int = 'int';
    case String = 'string';
    case Bool = 'bool';
    case Datetime = 'datetime';

    public function supportsOrdering(): bool
    {
        return $this === self::Int || $this === self::Datetime;
    }

    /** Normalise a value written in policy code. Null is never a comparable value. */
    public function policyValue(mixed $value, string $column): int|string|bool|DateTimeImmutable
    {
        return match (true) {
            $this === self::Int && is_int($value) => $value,
            $this === self::String && is_string($value) => $value,
            $this === self::Bool && is_bool($value) => $value,
            $this === self::Datetime && $value instanceof DateTimeInterface => self::toUtcSeconds($value),
            default => throw new AttributeTypeMismatch(sprintf(
                'Column "%s" is declared %s; policy value of type %s is not comparable to it.',
                $column,
                $this->value,
                get_debug_type($value),
            )),
        };
    }

    /** Normalise a raw (uncast) attribute. Never called with null: null is handled by the node. */
    public function rawValue(mixed $raw, string $column): int|string|bool|DateTimeImmutable
    {
        $normalised = match ($this) {
            self::Int => is_int($raw) ? $raw : (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : null),
            self::String => is_string($raw) ? $raw : null,
            self::Bool => match (true) {
                is_bool($raw) => $raw,
                $raw === 0, $raw === '0' => false,
                $raw === 1, $raw === '1' => true,
                default => null,
            },
            self::Datetime => self::parseDatetime($raw),
        };

        return $normalised ?? throw new AttributeTypeMismatch(sprintf(
            'Raw value of column "%s" (%s) cannot be read as %s.',
            $column,
            get_debug_type($raw),
            $this->value,
        ));
    }

    public function binding(int|string|bool|DateTimeImmutable $value): int|string
    {
        return match (true) {
            is_bool($value) => $value ? 1 : 0,
            $value instanceof DateTimeImmutable => $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    public function compare(int|string|bool|DateTimeImmutable $left, int|string|bool|DateTimeImmutable $right): int
    {
        if ($this === self::String) {
            // Exact byte comparison; only equality is ever asked of strings.
            return $left === $right ? 0 : strcmp((string) $left, (string) $right);
        }

        return $left <=> $right;
    }

    private static function toUtcSeconds(DateTimeInterface $value): DateTimeImmutable
    {
        $utc = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('G'), (int) $utc->format('i'), (int) $utc->format('s'));
    }

    private static function parseDatetime(mixed $raw): ?DateTimeImmutable
    {
        if ($raw instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($raw)->setTimezone(new DateTimeZone('UTC'));
        }

        if (! is_string($raw)) {
            return null;
        }

        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }

        return null;
    }
}

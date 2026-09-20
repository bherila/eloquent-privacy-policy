<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

use BWH\EloquentPrivacyPolicy\Exceptions\MissingFact;
use InvalidArgumentException;

/**
 * Immutable map of scalar snapshots. Objects are rejected so that a mutable
 * application model can never be captured in a context.
 *
 * @phpstan-type ScalarValue int|string|bool|float|null
 */
abstract readonly class ScalarBag
{
    /** @var array<string, int|string|bool|float|null|list<int|string|bool|float|null>> */
    private array $values;

    /** @param array<string, mixed> $values */
    final public function __construct(array $values = [])
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $list = [];

                foreach ($value as $item) {
                    $list[] = self::scalar($key, $item);
                }

                $clean[$key] = $list;

                continue;
            }

            $clean[$key] = self::scalar($key, $value);
        }

        $this->values = $clean;
    }

    abstract protected function label(): string;

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return int|string|bool|float|null|list<int|string|bool|float|null> */
    public function get(string $key): int|string|bool|float|array|null
    {
        if (! array_key_exists($key, $this->values)) {
            throw new MissingFact(sprintf('%s "%s" was not supplied.', $this->label(), $key));
        }

        return $this->values[$key];
    }

    public function bool(string $key): bool
    {
        $value = $this->get($key);

        return is_bool($value) ? $value : throw new MissingFact(sprintf('%s "%s" is not a boolean.', $this->label(), $key));
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_int($value) ? $value : throw new MissingFact(sprintf('%s "%s" is not an integer.', $this->label(), $key));
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_string($value) ? $value : throw new MissingFact(sprintf('%s "%s" is not a string.', $this->label(), $key));
    }

    /** @return list<int> */
    public function ints(string $key): array
    {
        $value = $this->get($key);

        if (! is_array($value)) {
            throw new MissingFact(sprintf('%s "%s" is not a list.', $this->label(), $key));
        }

        foreach ($value as $item) {
            if (! is_int($item)) {
                throw new MissingFact(sprintf('%s "%s" is not a list of integers.', $this->label(), $key));
            }
        }

        /** @var list<int> $value */
        return $value;
    }

    /** @return array<string, int|string|bool|float|null|list<int|string|bool|float|null>> */
    public function all(): array
    {
        return $this->values;
    }

    /** @param array<string, mixed> $values */
    public function with(array $values): static
    {
        return new static([...$this->values, ...$values]);
    }

    private static function scalar(string $key, mixed $value): int|string|bool|float|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf(
            'Context value "%s" must be a scalar snapshot, %s given.',
            $key,
            get_debug_type($value),
        ));
    }
}

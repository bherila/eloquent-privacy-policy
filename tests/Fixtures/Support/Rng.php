<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support;

/**
 * A tiny deterministic generator. Deliberately not mt_rand(): the property
 * suites must reproduce exactly from a printed seed on any platform and
 * without touching global RNG state another test might depend on.
 */
final class Rng
{
    private int $state;

    public function __construct(public readonly int $seed)
    {
        $this->state = ($seed & 0x7FFFFFFF) | 1;
    }

    public function next(): int
    {
        // Numerical Recipes LCG, kept inside 31 bits so it never overflows.
        $this->state = (int) ((($this->state * 1664525) + 1013904223) & 0x7FFFFFFF);

        return $this->state;
    }

    /** Uniform-ish integer in [0, $bound). */
    public function below(int $bound): int
    {
        return $bound <= 1 ? 0 : $this->next() % $bound;
    }

    /** Integer in [$low, $high] inclusive. */
    public function between(int $low, int $high): int
    {
        return $low + $this->below($high - $low + 1);
    }

    public function chance(int $percent): bool
    {
        return $this->below(100) < $percent;
    }

    /**
     * @template T
     *
     * @param non-empty-list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->below(count($items))];
    }

    /**
     * A deterministic permutation of $items (Fisher-Yates).
     *
     * @template T
     *
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffled(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->below($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }
}

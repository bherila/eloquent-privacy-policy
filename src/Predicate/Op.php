<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

enum Op: string
{
    case Eq = '=';
    case Lt = '<';
    case Lte = '<=';
    case Gt = '>';
    case Gte = '>=';

    public function isOrdered(): bool
    {
        return $this !== self::Eq;
    }

    public function holds(int $spaceship): bool
    {
        return match ($this) {
            self::Eq => $spaceship === 0,
            self::Lt => $spaceship < 0,
            self::Lte => $spaceship <= 0,
            self::Gt => $spaceship > 0,
            self::Gte => $spaceship >= 0,
        };
    }
}

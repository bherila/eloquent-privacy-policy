<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

use BWH\EloquentPrivacyPolicy\Exceptions\InvalidIdentifier;

/** Table and column names are trusted schema configuration; they are still checked. */
final class Identifier
{
    public static function assert(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidIdentifier(sprintf('"%s" is not a plain identifier.', $name));
        }

        return $name;
    }
}

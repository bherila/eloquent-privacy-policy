<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

use BWH\EloquentPrivacyPolicy\Exceptions\InvalidIdentifier;

/** Table and column names are trusted schema configuration; they are still checked. */
final class Identifier
{
    public static function assert(string $name): string
    {
        // \z, not $: "$" also matches before a trailing newline.
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
            throw new InvalidIdentifier(sprintf('"%s" is not a plain identifier.', $name));
        }

        return $name;
    }

    /**
     * A column of $table, given as "column" or "<table>.column". Returns the
     * qualified form; any other table is rejected.
     */
    public static function column(string $name, string $table): string
    {
        if (preg_match('/\A(?:([A-Za-z_][A-Za-z0-9_]*)\.)?([A-Za-z_][A-Za-z0-9_]*)\z/', $name, $parts) !== 1) {
            throw new InvalidIdentifier(sprintf('"%s" is not a plain column name.', $name));
        }

        if ($parts[1] !== '' && $parts[1] !== $table) {
            throw new InvalidIdentifier(sprintf('Only columns of "%s" may be referenced.', $table));
        }

        return $table.'.'.$parts[2];
    }
}

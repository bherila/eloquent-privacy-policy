<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Exceptions\PrivacyException;

/**
 * A row the executor had to lock and read is not there: an anchor, the target
 * of a non-creation, or a parent a foreign key names. Absence is a failure,
 * never "nothing to lock" — a lock that was not taken protects nothing.
 */
final class MissingLockedRow extends PrivacyException
{
    public static function anchor(Anchor $anchor): self
    {
        return new self(sprintf(
            'Anchor row %s.%s = %s does not exist. A missing anchor is a failure, not "nothing to lock".',
            $anchor->table,
            $anchor->keyColumn,
            (string) $anchor->key,
        ));
    }

    public static function row(string $role, string $table, string $column, int|string $key): self
    {
        return new self(sprintf('The %s of this action (%s.%s = %s) does not exist.', $role, $table, $column, (string) $key));
    }
}

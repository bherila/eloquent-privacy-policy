<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Predicate\Identifier;

/**
 * One row that must be held FOR UPDATE while an action authorises itself —
 * normally the resource-boundary parent a grant hangs off. Anchors are the
 * meeting point of the revocation protocol (contract 6.1): the executor and
 * every participating grant writer take the same rows in the same order.
 */
final readonly class Anchor
{
    public string $table;

    public string $keyColumn;

    public function __construct(
        string $table,
        public int|string $key,
        string $keyColumn = 'id',
    ) {
        $this->table = Identifier::assert($table);
        $this->keyColumn = Identifier::assert($keyColumn);
    }

    public static function of(string $table, int|string $key, string $keyColumn = 'id'): self
    {
        return new self($table, $key, $keyColumn);
    }

    /** Identity for de-duplication: the same row named twice is locked once. */
    public function identity(): string
    {
        return $this->table."\x1f".$this->keyColumn."\x1f".(is_int($this->key) ? 'i' : 's').$this->key;
    }

    /**
     * The total order the protocol locks in: table, then key. Integer keys sort
     * before string ones so that a mixed set still has exactly one order every
     * participant agrees on.
     */
    public static function compare(self $left, self $right): int
    {
        return strcmp($left->table, $right->table)
            ?: (int) is_string($left->key) <=> (int) is_string($right->key)
            ?: (is_int($left->key) && is_int($right->key)
                ? $left->key <=> $right->key
                : strcmp((string) $left->key, (string) $right->key))
            ?: strcmp($left->keyColumn, $right->keyColumn);
    }
}

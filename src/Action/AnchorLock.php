<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use Illuminate\Database\ConnectionInterface;

/**
 * The one implementation of "take the anchor locks", shared by the executor
 * and by {@see \BWH\EloquentPrivacyPolicy\Privacy::withAnchors()}. Two
 * implementations would be two orders, and two orders deadlock.
 */
final class AnchorLock
{
    /**
     * @param list<Anchor> $anchors
     *
     * @throws MissingLockedRow
     */
    public static function take(ConnectionInterface $connection, array $anchors): void
    {
        foreach (self::sorted($anchors) as $anchor) {
            $row = $connection->table($anchor->table)
                ->where($anchor->keyColumn, '=', $anchor->key)
                ->lockForUpdate()
                ->first([$anchor->keyColumn]);

            if ($row === null) {
                throw MissingLockedRow::anchor($anchor);
            }
        }
    }

    /**
     * De-duplicated and ordered by (table, key). Deterministic across processes:
     * this order is the only thing that keeps participating writers from
     * deadlocking each other.
     *
     * @param list<Anchor> $anchors
     * @return list<Anchor>
     */
    public static function sorted(array $anchors): array
    {
        $unique = [];

        foreach ($anchors as $anchor) {
            $unique[$anchor->identity()] = $anchor;
        }

        $ordered = array_values($unique);
        usort($ordered, Anchor::compare(...));

        return $ordered;
    }
}

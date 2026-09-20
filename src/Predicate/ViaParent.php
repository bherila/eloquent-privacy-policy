<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Predicate;

use Illuminate\Database\Eloquent\Model;

/**
 * "The parent row is visible under the parent's read policy." A placeholder:
 * the resolver expands it into an Exists over the parent table before either
 * interpreter sees it, and detects cycles while doing so.
 */
final readonly class ViaParent extends Predicate
{
    /** @param class-string<Model> $parent */
    private function __construct(
        public Col $foreignKey,
        public string $parent,
        public ?string $ownerKey,
    ) {
    }

    /** @param class-string<Model> $parent */
    public static function of(string $foreignKey, string $parent, ?string $ownerKey = null): self
    {
        if ($ownerKey !== null) {
            Identifier::assert($ownerKey);
        }

        return new self(Col::int($foreignKey), $parent, $ownerKey);
    }
}

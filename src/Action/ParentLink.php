<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Predicate\Identifier;
use Illuminate\Database\Eloquent\Model;

/**
 * How the executor finds the parent whose state authorises an action: the
 * foreign key on the target's own table and the model on the other end.
 *
 * The executor loads up to three rows through it — the current parent, the
 * creation parent, and, when the proposed changes touch the foreign key, the
 * parent the action would move the target to. Reparenting has to name that
 * proposed parent, because authorising against the parent the row is leaving
 * authorises nothing about the one it is joining.
 *
 * @template-covariant TParent of Model
 */
final readonly class ParentLink
{
    public string $foreignKey;

    public ?string $ownerKey;

    /** @param class-string<TParent> $parent */
    public function __construct(
        string $foreignKey,
        public string $parent,
        ?string $ownerKey = null,
    ) {
        $this->foreignKey = Identifier::assert($foreignKey);
        $this->ownerKey = $ownerKey === null ? null : Identifier::assert($ownerKey);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $parent
     * @return self<T>
     */
    public static function of(string $foreignKey, string $parent, ?string $ownerKey = null): self
    {
        return new self($foreignKey, $parent, $ownerKey);
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;

/**
 * What an action rule sees. The persisted pre-state and the proposed changes
 * are separate, so a dirty owner field can never authorise itself.
 */
final readonly class ActionInput
{
    /**
     * @param ?RowSnapshot $preState persisted state of the target, locked; null for a creation
     * @param ?RowSnapshot $parent persisted state of the current (or creation) parent, locked
     * @param ?RowSnapshot $proposedParent persisted state of the parent the action would move the target to
     * @param array<string, mixed> $changes proposed attribute changes, not yet applied
     */
    public function __construct(
        public string $action,
        public ?RowSnapshot $preState,
        public ?RowSnapshot $parent = null,
        public ?RowSnapshot $proposedParent = null,
        public array $changes = [],
    ) {
    }

    public function isCreation(): bool
    {
        return $this->preState === null;
    }

    public function changes(string $attribute): bool
    {
        return array_key_exists($attribute, $this->changes);
    }
}

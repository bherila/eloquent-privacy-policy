<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Neutral defaults for the parts of an action most actions do not use. Every
 * default is the conservative one: no anchors claimed, no parent, no facts, no
 * validation, no after-commit work.
 *
 * @template TModel of Model
 *
 * @implements Action<TModel>
 */
abstract class BaseAction implements Action
{
    public function targetKey(): int|string|null
    {
        return null;
    }

    public function changes(): array
    {
        return [];
    }

    public function anchors(): array
    {
        return [];
    }

    public function parent(): ?ParentLink
    {
        return null;
    }

    public function facts(): ?ActionFactProvider
    {
        return null;
    }

    public function validate(ActionInput $input): void
    {
    }

    public function version(Model $persisted): int|string|null
    {
        return null;
    }

    public function afterCommit(): ?Closure
    {
        return null;
    }
}

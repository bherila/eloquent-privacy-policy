<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use Illuminate\Database\Eloquent\Model;

/**
 * What a denial audit is allowed to know: who asked for what, and nothing of
 * the row. No attributes, no proposed changes, no rule ids, no exception —
 * an audit trail that leaks the record it is protecting is a second copy of
 * the problem.
 */
final readonly class DenialRecord
{
    /** @param class-string<Model> $model */
    public function __construct(
        public string $action,
        public string $model,
        public int|string|null $targetKey,
        public int|string|null $viewerId,
        public string $viewerType,
        public string $operation,
    ) {
    }

    /**
     * @template TModel of Model
     *
     * @param Action<TModel> $action
     */
    public static function of(Action $action, PrivacyContext $context): self
    {
        return new self(
            $action->name(),
            $action->model(),
            $action->targetKey(),
            $context->viewer->isAnonymous() ? null : $context->viewer->id(),
            $context->viewer->type,
            $context->operation->name,
        );
    }
}

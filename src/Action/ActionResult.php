<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Exceptions\PolicyNotRegistered;
use BWH\EloquentPrivacyPolicy\Privacy;
use Illuminate\Database\Eloquent\Model;

/**
 * What an action returns. It holds identifiers and the caller's context, never
 * the model that was persisted: handing back the hydrated instance would let a
 * write disclose a row the same viewer may not read.
 *
 * @template TModel of Model
 */
final readonly class ActionResult
{
    /**
     * @internal built by {@see ActionExecutor}
     *
     * @param class-string<TModel> $model
     */
    public function __construct(
        public Receipt $receipt,
        private string $model,
        private PrivacyContext $context,
    ) {
    }

    /**
     * The target as the caller may read it: a fresh query through the protected
     * read path, under the context the action ran with. Null when the caller
     * may write but not read — including when the model has no read policy at
     * all, which is the same disclosure answer arrived at sooner.
     *
     * @return TModel|null
     */
    public function readable(): ?Model
    {
        if ($this->receipt->targetKey === null) {
            return null;
        }

        try {
            Privacy::policyFor($this->model)->readRules();
        } catch (PolicyNotRegistered) {
            return null;
        }

        return Privacy::query($this->model, $this->context)->find($this->receipt->targetKey);
    }
}

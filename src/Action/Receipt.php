<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

use Illuminate\Database\Eloquent\Model;

/**
 * Proof that an action ran, in identifiers only. It is what the caller always
 * gets back: a write may be authorised by a viewer who may not read the row it
 * changed, and a receipt discloses nothing beyond what that viewer already
 * named.
 */
final readonly class Receipt
{
    /** @param class-string<Model> $model */
    public function __construct(
        public string $action,
        public string $model,
        public int|string|null $targetKey,
        public int|string|null $version = null,
    ) {
    }
}

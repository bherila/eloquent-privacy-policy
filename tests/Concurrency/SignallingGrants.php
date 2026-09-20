<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Action\LockingReads;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;

/**
 * "May this viewer write in this space?", read under lock — and the place the
 * races are wound up, because the executor calls it exactly once, after the
 * anchor lock and before the decision.
 *
 * A child announces that it holds the anchor here, and can be made to wait
 * here for the other process, so an interleaving is arranged rather than
 * hoped for.
 */
final class SignallingGrants implements ActionFactProvider
{
    public function __construct(
        private readonly Signals $signals,
        private readonly ?string $emit = null,
        private readonly ?string $await = null,
        private readonly int $holdMs = 0,
    ) {
    }

    public function facts(PrivacyContext $context, ActionInput $input, LockingReads $reads): Facts
    {
        if ($this->emit !== null) {
            $this->signals->emit($this->emit);
        }

        if ($this->await !== null) {
            $this->signals->await($this->await);
        }

        if ($this->holdMs > 0) {
            usleep($this->holdMs * 1_000);
        }

        $grant = $reads->table(Fixture::GRANTS)
            ->where('space_id', '=', $input->parent?->get('id'))
            ->where('user_id', '=', $context->viewer->id())
            ->first(['id']);

        return new Facts(['may_write' => $grant !== null]);
    }
}

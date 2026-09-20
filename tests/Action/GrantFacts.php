<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Action\LockingReads;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;

/**
 * Resolves "may the viewer write in this workspace?" from the grant table, with
 * the locking read the executor insists on. The workspace it asks about is the
 * proposed parent when the action reparents, and the current one otherwise:
 * the grant that matters is the one on the workspace the row will live in.
 */
final class GrantFacts implements ActionFactProvider
{
    public const string TABLE = 'act_grants';

    public function __construct(private readonly string $ability = 'write')
    {
    }

    public function facts(PrivacyContext $context, ActionInput $input, LockingReads $reads): Facts
    {
        $workspace = $input->proposedParent ?? $input->parent;

        if ($workspace === null || $context->viewer->isAnonymous()) {
            return new Facts(['may_write' => false]);
        }

        $grant = $reads->table(self::TABLE)
            ->where('workspace_id', '=', $workspace->get('id'))
            ->where('user_id', '=', $context->viewer->id())
            ->where('ability', '=', $this->ability)
            ->first(['id']);

        return new Facts(['may_write' => $grant !== null]);
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\QuickStart;

use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Action\LockingReads;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;

/**
 * "May the viewer write in this project?", read under lock as the revocation
 * protocol requires (contract 6.1).
 */
final class RecordGrants implements ActionFactProvider
{
    public function facts(PrivacyContext $context, ActionInput $input, LockingReads $reads): Facts
    {
        $grant = $reads->table('doc_record_grants')
            ->where('project_id', '=', $input->parent?->get('id'))
            ->where('user_id', '=', $context->viewer->id())
            ->where('ability', '=', 'write')
            ->first(['id']);

        return new Facts(['may_write' => $grant !== null]);
    }
}

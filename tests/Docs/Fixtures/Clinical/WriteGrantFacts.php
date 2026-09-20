<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical;

use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Action\LockingReads;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;

/** "Does the viewer hold an active, unexpired write grant on this patient?", read under lock. */
final class WriteGrantFacts implements ActionFactProvider
{
    public function facts(PrivacyContext $context, ActionInput $input, LockingReads $reads): Facts
    {
        $patientId = $input->parent?->get('id');

        $grant = $reads->table('doc_patient_grants')
            ->where('patient_id', '=', $patientId)
            ->where('user_id', '=', $context->viewer->id())
            ->where('ability', '=', 'write')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first(['id']);

        return new Facts(['may_write' => $grant !== null]);
    }
}

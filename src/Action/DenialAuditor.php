<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

/**
 * Optional hook for recording denied actions. It runs after the rollback and
 * outside the transaction, so an audit write is never undone by the very
 * rollback it is reporting, and a slow auditor never holds an anchor lock.
 *
 * It is handed identifiers only; see {@see DenialRecord}.
 */
interface DenialAuditor
{
    public function denied(DenialRecord $record): void;
}

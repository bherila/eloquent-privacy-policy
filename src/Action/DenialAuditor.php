<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Action;

/**
 * Optional hook for recording denied actions. It runs after the executor's own
 * rollback and outside its transaction, so an audit write is not undone by that
 * rollback and a slow auditor never holds an anchor lock.
 *
 * That holds at the outermost level only. When the caller already has a
 * transaction open the executor works in a savepoint, and the auditor runs
 * inside the caller's transaction: an audit written on the same connection is
 * discarded if the caller then rolls back, and an exception thrown here replaces
 * the {@see \BWH\EloquentPrivacyPolicy\Exceptions\ActionDenied}. An auditor that
 * must survive a caller's rollback writes on its own connection.
 *
 * It is handed identifiers only; see {@see DenialRecord}.
 */
interface DenialAuditor
{
    public function denied(DenialRecord $record): void;
}

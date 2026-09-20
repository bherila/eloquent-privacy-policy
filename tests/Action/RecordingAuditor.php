<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\DenialAuditor;
use BWH\EloquentPrivacyPolicy\Action\DenialRecord;

/** Collects what the executor tells an auditor, so a test can check exactly that. */
final class RecordingAuditor implements DenialAuditor
{
    /** @var list<DenialRecord> */
    public array $records = [];

    public function denied(DenialRecord $record): void
    {
        $this->records[] = $record;
    }
}

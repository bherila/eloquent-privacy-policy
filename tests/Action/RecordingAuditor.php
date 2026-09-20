<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\DenialAuditor;
use BWH\EloquentPrivacyPolicy\Action\DenialRecord;
use Illuminate\Support\Facades\DB;

/** Collects what the executor tells an auditor, so a test can check exactly that. */
final class RecordingAuditor implements DenialAuditor
{
    /** @var list<DenialRecord> */
    public array $records = [];

    /** @var list<int> the default connection's transaction level when each denial was reported */
    public array $transactionLevels = [];

    public function denied(DenialRecord $record): void
    {
        $this->records[] = $record;
        $this->transactionLevels[] = DB::transactionLevel();
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical;

use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\BaseAction;
use BWH\EloquentPrivacyPolicy\Action\ParentLink;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * No ActionFactProvider here: record.delete's rule set reads only the parent
 * snapshot the executor already loaded, never a grant fact.
 *
 * @extends BaseAction<ClinicalRecord>
 */
final class ClinicalDeleteAction extends BaseAction
{
    public function __construct(
        private readonly int $recordId,
        private readonly int $patientId,
    ) {
    }

    public function name(): string
    {
        return 'record.delete';
    }

    public function model(): string
    {
        return ClinicalRecord::class;
    }

    public function targetKey(): int
    {
        return $this->recordId;
    }

    public function anchors(): array
    {
        return [Anchor::of('doc_patients', $this->patientId)];
    }

    public function parent(): ParentLink
    {
        return ParentLink::of('patient_id', Patient::class);
    }

    /**
     * @param ClinicalRecord $target
     * @return ClinicalRecord
     */
    public function persist(Model $target, ConnectionInterface $connection): Model
    {
        $target->delete();

        return $target;
    }
}

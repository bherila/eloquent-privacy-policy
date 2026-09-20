<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical;

use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\BaseAction;
use BWH\EloquentPrivacyPolicy\Action\ParentLink;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A caregiver's "write" grant is enough for this one: contrast with
 * {@see ClinicalDeleteAction}, which the same grant cannot satisfy.
 *
 * @extends BaseAction<ClinicalRecord>
 */
final class ClinicalWriteAction extends BaseAction
{
    public function __construct(
        private readonly int $recordId,
        private readonly int $patientId,
        private readonly string $note,
    ) {
    }

    public function name(): string
    {
        return 'record.update';
    }

    public function model(): string
    {
        return ClinicalRecord::class;
    }

    public function targetKey(): int
    {
        return $this->recordId;
    }

    public function changes(): array
    {
        return ['note' => $this->note];
    }

    public function anchors(): array
    {
        return [Anchor::of('doc_patients', $this->patientId)];
    }

    public function parent(): ParentLink
    {
        return ParentLink::of('patient_id', Patient::class);
    }

    public function facts(): ActionFactProvider
    {
        return new WriteGrantFacts();
    }

    /**
     * @param ClinicalRecord $target
     * @return ClinicalRecord
     */
    public function persist(Model $target, ConnectionInterface $connection): Model
    {
        $target->note = $this->note;
        $target->save();

        return $target;
    }
}

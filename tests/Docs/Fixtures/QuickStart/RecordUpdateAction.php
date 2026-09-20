<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\QuickStart;

use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\BaseAction;
use BWH\EloquentPrivacyPolicy\Action\ParentLink;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Rename a record. Anchored on its project (the resource boundary a grant
 * revocation would also anchor on), authorised by a "write" grant read under
 * lock, never by the caller's own copy of the row.
 *
 * @extends BaseAction<Record>
 */
final class RecordUpdateAction extends BaseAction
{
    public function __construct(
        private readonly int $recordId,
        private readonly int $projectId,
        private readonly string $title,
    ) {
    }

    public function name(): string
    {
        return 'record.update';
    }

    public function model(): string
    {
        return Record::class;
    }

    public function targetKey(): int
    {
        return $this->recordId;
    }

    public function changes(): array
    {
        return ['title' => $this->title];
    }

    public function anchors(): array
    {
        return [Anchor::of('doc_projects', $this->projectId)];
    }

    public function parent(): ParentLink
    {
        return ParentLink::of('project_id', Project::class);
    }

    public function facts(): ActionFactProvider
    {
        return new RecordGrants();
    }

    /**
     * @param Record $target
     * @return Record
     */
    public function persist(Model $target, ConnectionInterface $connection): Model
    {
        $target->title = $this->title;
        $target->revision = $target->revision + 1;
        $target->save();

        return $target;
    }

    /** @param Record $persisted */
    public function version(Model $persisted): int
    {
        return $persisted->revision;
    }
}

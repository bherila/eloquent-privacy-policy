<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Finance;

use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\BaseAction;
use BWH\EloquentPrivacyPolicy\Action\ParentLink;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends BaseAction<LedgerEntry>
 */
final class LedgerEntryMoveAction extends BaseAction
{
    public function __construct(
        private readonly int $entryId,
        private readonly int $currentAccountNo,
        private readonly int $newAccountNo,
    ) {
    }

    public function name(): string
    {
        return 'entry.move';
    }

    public function model(): string
    {
        return LedgerEntry::class;
    }

    public function targetKey(): int
    {
        return $this->entryId;
    }

    public function changes(): array
    {
        return ['account_no' => $this->newAccountNo];
    }

    public function anchors(): array
    {
        return [
            Anchor::of('doc_ledger_accounts', $this->currentAccountNo, 'account_no'),
            Anchor::of('doc_ledger_accounts', $this->newAccountNo, 'account_no'),
        ];
    }

    public function parent(): ParentLink
    {
        return ParentLink::of('account_no', LedgerAccount::class);
    }

    /**
     * @param LedgerEntry $target
     * @return LedgerEntry
     */
    public function persist(Model $target, ConnectionInterface $connection): Model
    {
        $target->account_no = $this->newAccountNo;
        $target->save();

        return $target;
    }
}

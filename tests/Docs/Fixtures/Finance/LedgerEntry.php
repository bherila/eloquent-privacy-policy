<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Finance;

use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * No owner column at all: an entry's visibility comes only from its account,
 * via ViaParent. Moving an entry to a different account (entry.move) needs
 * the viewer to hold the account itself — both the one the entry is leaving
 * and, when the account is what changed, the one it would join.
 *
 * @property int $id
 * @property int $account_no
 * @property int $amount
 * @property string $description
 */
class LedgerEntry extends Model
{
    use HasPrivacyPolicy;

    protected $table = 'doc_ledger_entries';

    protected $guarded = [];

    public $timestamps = false;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)
            ->read(RuleSet::define()->grant(
                Rule::of('via-account', fn () => ViaParent::of('account_no', LedgerAccount::class)),
            ))
            ->action('entry.move', RuleSet::define()->grant(
                Rule::action('owns-both-accounts', fn (PrivacyContext $c, ActionInput $i) => self::holds($i->parent, $c)
                    && (! $i->changes('account_no') || self::holds($i->proposedParent, $c))
                    ? Decision::Allow
                    : Decision::Skip),
            ));
    }

    private static function holds(?RowSnapshot $account, PrivacyContext $c): bool
    {
        return $account !== null && (string) $account->get('holder_id') === (string) $c->viewer->id();
    }
}

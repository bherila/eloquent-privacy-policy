<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Has no owner column at all: ownership exists only through acct_id, and the
 * parent's key is not "id", so ViaParent needs an explicit owner key.
 */
class FeeSchedule extends Model
{
    use HasPrivacyPolicy;

    public $timestamps = false;

    protected $table = 'fx_fee_schedules';

    protected $guarded = [];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('account', fn () => ViaParent::of('acct_id', Account::class, 'acct_id')),
            ),
        );
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'acct_id', 'acct_id');
    }
}

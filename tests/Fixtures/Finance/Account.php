<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Custom primary key (acct_id) and a non-standard owner column (acct_owner).
 * Grants are keyed on the owner, not on the account.
 */
class Account extends Model
{
    use HasPrivacyPolicy;

    public $timestamps = false;

    public $incrementing = true;

    protected $table = 'fx_accounts';

    protected $primaryKey = 'acct_id';

    protected $keyType = 'int';

    protected $guarded = [];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('owner', fn (PrivacyContext $c) => Col::int('acct_owner')->eq($c->viewer->intId())),
                Rule::of('grant', fn (PrivacyContext $c) => Exists::in('fx_account_grants')
                    ->match('acct_owner', 'owner_id')
                    ->where(Predicate::all(
                        Col::int('grantee_id')->eq($c->viewer->intId()),
                        Col::datetime('revoked_at')->isNull(),
                    ))),
            ),
        );
    }

    /** @return HasMany<FeeSchedule, $this> */
    public function feeSchedules(): HasMany
    {
        return $this->hasMany(FeeSchedule::class, 'acct_id', 'acct_id');
    }
}

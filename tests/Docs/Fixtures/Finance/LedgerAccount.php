<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Finance;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom primary key ("account_no", not "id") and an owner column
 * ("holder_id") distinct from it.
 */
class LedgerAccount extends Model
{
    use HasPrivacyPolicy;

    protected $table = 'doc_ledger_accounts';

    protected $primaryKey = 'account_no';

    protected $guarded = [];

    public $timestamps = false;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('holder', fn (PrivacyContext $c) => Col::int('holder_id')->eq($c->viewer->intId())),
            ),
        );
    }
}

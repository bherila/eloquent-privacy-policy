<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use Illuminate\Database\Eloquent\Model;

/**
 * Declares its own save(), which silently wins over the trait's guard. The
 * protected builder must refuse the model outright rather than hand out
 * instances whose write guard is gone.
 */
class GuardedOverride extends Model
{
    use HasPrivacyPolicy;

    public $timestamps = false;

    protected $table = 'fx_guarded';

    protected $guarded = [];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('owner', fn (PrivacyContext $c) => Col::int('owner_id')->eq($c->viewer->intId())),
            ),
        );
    }

    public function save(array $options = [])
    {
        return parent::save($options);
    }
}

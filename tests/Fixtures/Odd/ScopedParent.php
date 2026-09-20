<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A parent carrying a global scope other than soft deletes (a tenant boundary).
 * A ViaParent to it cannot reproduce that scope inside its EXISTS, so it must
 * be refused rather than compiled without it.
 */
class ScopedParent extends Model
{
    use HasPrivacyPolicy;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'fx_scoped_parent';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new class implements Scope {
            public function apply(Builder $builder, Model $model): void
            {
                $builder->where('tenant_id', '=', 1);
            }
        });
    }

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(Rule::of('open', fn () => Predicate::always())),
        );
    }
}

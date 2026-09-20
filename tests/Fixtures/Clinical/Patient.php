<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical;

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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Visible to its owner, or to anyone holding a grant that is neither revoked
 * nor expired at the context's clock.
 */
class Patient extends Model
{
    use HasPrivacyPolicy;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'fx_patients';

    protected $guarded = [];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('owner', fn (PrivacyContext $c) => Col::int('owner_id')->eq($c->viewer->intId())),
                Rule::of('grant', fn (PrivacyContext $c) => Exists::in('fx_patient_grants')
                    ->match('id', 'patient_id')
                    ->where(Predicate::all(
                        Col::int('user_id')->eq($c->viewer->intId()),
                        Col::datetime('revoked_at')->isNull(),
                        Predicate::any(
                            Col::datetime('expires_at')->isNull(),
                            Col::datetime('expires_at')->gt($c->now),
                        ),
                    ))),
            ),
        );
    }

    /** @return HasMany<ClinicalRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(ClinicalRecord::class, 'patient_id');
    }

    /**
     * Deliberately constrained: the protected path must refuse it.
     *
     * @return HasMany<ClinicalRecord, $this>
     */
    public function openRecords(): HasMany
    {
        return $this->hasMany(ClinicalRecord::class, 'patient_id')->where('review_status', 'open');
    }
}

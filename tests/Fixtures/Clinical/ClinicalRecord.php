<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Visibility derives entirely from the parent patient; an embargoed record is
 * denied outright, which a grant can never override.
 */
class ClinicalRecord extends Model
{
    use HasPrivacyPolicy;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'fx_clinical_records';

    protected $guarded = [];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()
                ->deny(Rule::of('embargoed', fn () => Col::string('review_status')->eq('embargoed')))
                ->grant(Rule::of('patient', fn () => ViaParent::of('patient_id', Patient::class))),
        );
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}

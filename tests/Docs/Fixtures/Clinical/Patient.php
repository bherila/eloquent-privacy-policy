<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient is visible to themselves or to anyone holding an active,
 * unexpired grant on their record. The grant carries an ability
 * ("view"/"write"); either ability is enough to see the patient exists, but
 * see ClinicalRecord below for where the two abilities start to differ.
 */
class Patient extends Model
{
    use HasPrivacyPolicy;

    protected $table = 'doc_patients';

    protected $guarded = [];

    public $timestamps = false;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('self', fn (PrivacyContext $c) => Col::int('user_id')->eq($c->viewer->intId())),
                Rule::of('grant', fn (PrivacyContext $c) => Exists::in('doc_patient_grants')
                    ->match('id', 'patient_id')
                    ->where(Predicate::all(
                        Col::int('user_id')->eq($c->viewer->intId()),
                        Predicate::any(Col::datetime('expires_at')->isNull(), Col::datetime('expires_at')->gt($c->now)),
                    ))),
            ),
        );
    }
}

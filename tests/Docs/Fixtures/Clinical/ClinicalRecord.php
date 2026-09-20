<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Clinical;

use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use Illuminate\Database\Eloquent\Model;

/**
 * A clinical record is visible whenever its patient is (child visibility via
 * ViaParent: no separate owner/grant check is written here). Updating one
 * only needs a "write" grant fact; deleting one does not — a grant, however
 * broad its ability, never satisfies record.delete. Only the patient can.
 *
 * @property int $id
 * @property int $patient_id
 * @property string $note
 */
class ClinicalRecord extends Model
{
    use HasPrivacyPolicy;

    protected $table = 'doc_clinical_records';

    protected $guarded = [];

    public $timestamps = false;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)
            ->read(RuleSet::define()->grant(
                Rule::of('via-patient', fn () => ViaParent::of('patient_id', Patient::class)),
            ))
            ->action('record.update', RuleSet::define()->grant(
                Rule::action('write-grant', fn (PrivacyContext $c) => $c->facts->bool('may_write')
                    ? Decision::Allow
                    : Decision::Skip),
            ))
            ->action('record.delete', RuleSet::define()->grant(
                Rule::action('is-patient', fn (PrivacyContext $c, ActionInput $i) => $i->parent !== null
                    && (string) $i->parent->get('user_id') === (string) $c->viewer->id()
                    ? Decision::Allow
                    : Decision::Skip),
            ));
    }
}

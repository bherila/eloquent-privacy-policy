<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\QuickStart;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Predicate\ViaParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visible when: the record's tenant matches (mandatory) AND ( staff (privileged)
 * OR ( not locked (deny) AND ( author, shared, or the project is visible ) ) ).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $project_id
 * @property int $author_id
 * @property bool $locked
 * @property string $title
 * @property int $revision
 */
class Record extends Model
{
    use HasPrivacyPolicy;

    protected $table = 'doc_records';

    protected $guarded = [];

    public $timestamps = false;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)
            ->read(RuleSet::define()
                ->mandatory(Rule::of('tenant', fn (PrivacyContext $c) => Col::int('tenant_id')->eq($c->scope->int('tenant_id'))))
                ->privileged(Rule::of('staff', fn (PrivacyContext $c) => Predicate::constant($c->facts->bool('is_staff'))))
                ->deny(Rule::of('locked', fn () => Col::bool('locked')->eq(true)))
                ->grant(
                    Rule::of('author', fn (PrivacyContext $c) => Col::int('author_id')->eq($c->viewer->intId())),
                    Rule::of('shared', fn (PrivacyContext $c) => Exists::in('doc_record_shares')
                        ->match('id', 'record_id')
                        ->where(Predicate::all(
                            Col::int('user_id')->eq($c->viewer->intId()),
                            Predicate::any(Col::datetime('expires_at')->isNull(), Col::datetime('expires_at')->gt($c->now)),
                        ))),
                    Rule::of('project', fn () => ViaParent::of('project_id', Project::class)),
                ))
            ->action('record.update', RuleSet::define()->grant(
                Rule::action('write-grant', fn (PrivacyContext $c) => $c->facts->bool('may_write')
                    ? Decision::Allow
                    : Decision::Skip),
            ));
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}

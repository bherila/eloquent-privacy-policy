<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Exercises all five stages at once:
 *
 *   mandatory  the operation's workspace boundary
 *   privileged a boolean fact ("is_moderator")
 *   deny       archived
 *   grant      own question, FAQ, tagged collaborator, eligible organisation
 *   terminal   default Deny
 *
 * The relations below deliberately include shapes the protected path must
 * refuse: a HasOne, a BelongsToMany, a MorphTo, a constrained HasMany, and a
 * HasMany to a model with no policy.
 */
class Question extends Model
{
    use HasPrivacyPolicy;

    public $timestamps = false;

    protected $table = 'fx_questions';

    protected $guarded = [];

    /** @var list<string> */
    protected $appends = ['collaborator_count'];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()
                ->mandatory(Rule::of('workspace', fn (PrivacyContext $c) => Col::int('workspace_id')->eq($c->scope->int('workspace_id'))))
                ->privileged(Rule::of('moderator', fn (PrivacyContext $c) => Predicate::constant($c->facts->bool('is_moderator'))))
                ->deny(Rule::of('archived', fn () => Col::bool('archived')->eq(true)))
                ->grant(
                    Rule::of('author', fn (PrivacyContext $c) => Col::int('author_id')->eq($c->viewer->intId())),
                    Rule::of('faq', fn () => Col::bool('is_faq')->eq(true)),
                    Rule::of('collaborator', fn (PrivacyContext $c) => Exists::in('fx_question_collaborators')
                        ->match('id', 'question_id')
                        ->where(Col::int('user_id')->eq($c->viewer->intId()))),
                    Rule::of('organization', fn (PrivacyContext $c) => Col::int('organization_id')
                        ->in($c->facts->ints('eligible_organization_ids'))),
                ),
        );
    }

    /** An accessor that touches a relation; serialisation must stay protected. */
    public function getCollaboratorCountAttribute(): int
    {
        return $this->collaborators->count();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /** @return HasMany<QuestionCollaborator, $this> */
    public function collaborators(): HasMany
    {
        return $this->hasMany(QuestionCollaborator::class, 'question_id');
    }

    /**
     * Extra constraints: unsupported.
     *
     * @return HasMany<QuestionCollaborator, $this>
     */
    public function namedCollaborators(): HasMany
    {
        return $this->hasMany(QuestionCollaborator::class, 'question_id')->whereNotNull('user_id');
    }

    /**
     * No policy on the related model: unsupported.
     *
     * @return HasMany<AuditNote, $this>
     */
    public function auditNotes(): HasMany
    {
        return $this->hasMany(AuditNote::class, 'question_id');
    }

    /**
     * Wrong relation type: unsupported.
     *
     * @return HasOne<QuestionCollaborator, $this>
     */
    public function firstCollaborator(): HasOne
    {
        return $this->hasOne(QuestionCollaborator::class, 'question_id');
    }

    /**
     * Wrong relation type: unsupported.
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function tagged(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'fx_question_tags', 'question_id', 'workspace_id');
    }

    /**
     * Wrong relation type: unsupported.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }
}

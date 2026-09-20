<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Qa;

use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use Illuminate\Database\Eloquent\Model;

/**
 * Visible inside one workspace only (the mandatory boundary reads the
 * context's resource scope, not a column comparison against another row).
 * Within that boundary: your own question, any FAQ, anything you are tagged
 * a collaborator on, or anything belonging to an organisation you are a
 * member of. An archived question is hidden from everyone regardless.
 *
 * question.edit is a separate, narrower rule set: none of FAQ, collaborator
 * or organisation membership lets you edit, only authorship does. Being able
 * to see a thread is not being able to change it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $author_id
 * @property bool $is_faq
 * @property int|null $org_id
 * @property bool $archived
 * @property string $title
 */
class Question extends Model
{
    use HasPrivacyPolicy;

    protected $table = 'doc_questions';

    protected $guarded = [];

    public $timestamps = false;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)
            ->read(RuleSet::define()
                ->mandatory(Rule::of('workspace', fn (PrivacyContext $c) => Col::int('workspace_id')->eq($c->scope->int('workspace_id'))))
                ->deny(Rule::of('archived', fn () => Col::bool('archived')->eq(true)))
                ->grant(
                    Rule::of('own', fn (PrivacyContext $c) => Col::int('author_id')->eq($c->viewer->intId())),
                    Rule::of('faq', fn () => Col::bool('is_faq')->eq(true)),
                    Rule::of('collaborator', fn (PrivacyContext $c) => Exists::in('doc_question_collaborators')
                        ->match('id', 'question_id')
                        ->where(Col::int('user_id')->eq($c->viewer->intId()))),
                    Rule::of('organisation', fn (PrivacyContext $c) => Col::int('org_id')->in($c->facts->ints('member_org_ids'))),
                ))
            ->action('question.edit', RuleSet::define()->grant(
                Rule::action('author', fn (PrivacyContext $c, ActionInput $i) => $i->preState !== null
                    && (string) $i->preState->get('author_id') === (string) $c->viewer->id()
                    ? Decision::Allow
                    : Decision::Skip),
            ));
    }
}

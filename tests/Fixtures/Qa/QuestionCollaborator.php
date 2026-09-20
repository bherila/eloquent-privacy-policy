<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use Illuminate\Database\Eloquent\Model;

/** A collaborator row is visible only to the collaborator it names. */
class QuestionCollaborator extends Model
{
    use HasPrivacyPolicy;

    public $timestamps = false;

    protected $table = 'fx_question_collaborators';

    protected $guarded = [];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('self', fn (PrivacyContext $c) => Col::int('user_id')->eq($c->viewer->intId())),
            ),
        );
    }
}

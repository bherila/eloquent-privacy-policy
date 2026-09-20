<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\QuickStart;

use BWH\EloquentPrivacyPolicy\Concerns\HasPrivacyPolicy;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasPrivacyPolicy;

    protected $table = 'doc_projects';

    protected $guarded = [];

    public $timestamps = false;

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(
            RuleSet::define()->grant(
                Rule::of('owner', fn (PrivacyContext $c) => Col::int('owner_id')->eq($c->viewer->intId())),
            ),
        );
    }

    /** @return HasMany<Record, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}

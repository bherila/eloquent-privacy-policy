<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Odd;

use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;

/**
 * A record whose model always eager-loads its parent, and which every viewer
 * may see: the only thing between a viewer and the parent is the parent's policy.
 */
class AutoloadingRecord extends ClinicalRecord
{
    /** @var list<string> */
    protected $with = ['patient'];

    public static function privacyPolicy(): ModelPolicy
    {
        return ModelPolicy::for(self::class)->read(RuleSet::define()->terminal(Decision::Allow));
    }
}

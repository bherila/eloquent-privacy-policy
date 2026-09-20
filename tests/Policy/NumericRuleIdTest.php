<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Policy;

use BWH\EloquentPrivacyPolicy\Decision;
use BWH\EloquentPrivacyPolicy\Policy\ModelPolicy;
use BWH\EloquentPrivacyPolicy\Policy\PolicyResolver;
use BWH\EloquentPrivacyPolicy\Policy\Rule;
use BWH\EloquentPrivacyPolicy\Policy\RuleSet;
use BWH\EloquentPrivacyPolicy\Policy\StageReducer;
use BWH\EloquentPrivacyPolicy\Predicate\Col;
use BWH\EloquentPrivacyPolicy\Privacy;
use BWH\EloquentPrivacyPolicy\Runtime\ExistsFacts;
use BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;

/** PHP turns a numeric-string array key such as "10" into an int; a rule id must survive that. */
final class NumericRuleIdTest extends FixtureTestCase
{
    public function test_a_numeric_rule_id_evaluates_at_runtime(): void
    {
        Privacy::register(ClinicalRecord::class, ModelPolicy::for(ClinicalRecord::class)->read(
            RuleSet::define()->grant(Rule::of('10', fn () => Col::int('patient_id')->eq(1))),
        ));

        $resolved = (new PolicyResolver())->resolveRead(ClinicalRecord::class, $this->context(1));

        $this->assertSame(Decision::Allow, $resolved->decide(
            new RowSnapshot(['patient_id' => 1]),
            new ExistsFacts(),
            new PredicateEvaluator(),
            new StageReducer(),
        ));
    }
}

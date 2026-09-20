<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Query;

use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalRecord;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\ClinicalSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical\Patient;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\Account;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\FeeSchedule;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance\FinanceSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\FixtureTestCase;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\QaSeed;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Question;
use BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa\Workspace;

/**
 * End-to-end visibility of the three synthetic domains, asserted through both
 * interpreters so the SQL and the PHP reading of each policy agree.
 */
final class DomainVisibilityTest extends FixtureTestCase
{
    // ----- clinical ----------------------------------------------------------

    public function test_patient_visibility_is_owner_or_an_active_unexpired_grant(): void
    {
        $this->createClinicalSchema();
        ClinicalSeed::seed();

        $this->assertBothInterpretersSee(Patient::class, $this->context(1), ClinicalSeed::PATIENTS_VISIBLE_TO_1);
        $this->assertBothInterpretersSee(Patient::class, $this->context(2), ClinicalSeed::PATIENTS_VISIBLE_TO_2);
        $this->assertBothInterpretersSee(Patient::class, $this->context(99), []);
        $this->assertBothInterpretersSee(Patient::class, $this->context(null), []);
    }

    public function test_a_grant_that_expires_after_the_context_clock_still_counts(): void
    {
        $this->createClinicalSchema();
        ClinicalSeed::seed();

        // Patient 5's only grant expired on 2026-05-01; the clock is 2026-06-01.
        $this->assertNotContains(5, ClinicalSeed::PATIENTS_VISIBLE_TO_1);

        $context = $this->context(1);

        $this->assertSame([], Patient::privacyQuery($context)->whereIn('id', [4, 5, 6])->get()->modelKeys());
    }

    public function test_record_visibility_derives_from_the_parent_and_the_deny_rule_is_exact(): void
    {
        $this->createClinicalSchema();
        ClinicalSeed::seed();

        $this->assertBothInterpretersSee(ClinicalRecord::class, $this->context(1), ClinicalSeed::RECORDS_VISIBLE_TO_1);
        $this->assertBothInterpretersSee(ClinicalRecord::class, $this->context(2), ClinicalSeed::RECORDS_VISIBLE_TO_2);

        // 2 is exactly "embargoed" and denied; 10 ("Embargoed") and 11
        // ("embargoed ") differ only in case / trailing space and are not.
        $visible = ClinicalRecord::privacyQuery($this->context(1))->get()->modelKeys();

        $this->assertNotContains(2, $visible);
        $this->assertContains(10, $visible);
        $this->assertContains(11, $visible);
    }

    public function test_a_record_whose_parent_is_soft_deleted_or_missing_is_invisible(): void
    {
        $this->createClinicalSchema();
        ClinicalSeed::seed();

        $visible = ClinicalRecord::privacyQuery($this->context(1))->get()->modelKeys();

        $this->assertNotContains(6, $visible, 'parent patient 6 is soft-deleted');
        $this->assertNotContains(8, $visible, 'record 8 has a NULL parent key');
        $this->assertNotContains(9, $visible, 'record 9 points at a row that does not exist');
        $this->assertNotContains(7, $visible, 'record 7 is itself soft-deleted');
    }

    // ----- Q&A ---------------------------------------------------------------

    public function test_the_workspace_boundary_is_mandatory_for_every_viewer(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $this->assertBothInterpretersSee(Question::class, $this->qa(1), QaSeed::VISIBLE_TO_1);
        $this->assertBothInterpretersSee(Question::class, $this->qa(1, moderator: true), QaSeed::VISIBLE_TO_1_MODERATOR);

        foreach ([QaSeed::VISIBLE_TO_1, QaSeed::VISIBLE_TO_1_MODERATOR] as $set) {
            $this->assertNotContains(7, $set, 'question 7 lives in another workspace');
            $this->assertNotContains(11, $set, 'question 11 has a NULL workspace');
        }
    }

    public function test_a_privileged_rule_overrides_deny_but_never_the_mandatory_boundary(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $moderator = Question::privacyQuery($this->qa(1, moderator: true))->orderBy('id')->get()->modelKeys();

        $this->assertContains(6, $moderator, 'archived rows are visible to a privileged viewer');
        $this->assertContains(9, $moderator);
        $this->assertNotContains(7, $moderator, 'the mandatory boundary still applies');
    }

    public function test_an_empty_fact_list_grants_nothing_rather_than_everything(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $this->assertBothInterpretersSee(Question::class, $this->qa(1, organizations: []), QaSeed::VISIBLE_TO_1_NO_ORGS);
    }

    public function test_the_boundary_of_another_workspace_yields_nothing(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $this->assertBothInterpretersSee(Question::class, $this->qa(1, workspace: 2), [7]);
        $this->assertBothInterpretersSee(Question::class, $this->qa(2, workspace: 3), []);
    }

    public function test_the_workspace_itself_is_bounded_by_the_resource_scope(): void
    {
        $this->createQaSchema();
        QaSeed::seed();

        $this->assertBothInterpretersSee(Workspace::class, $this->qa(1), [1]);
        $this->assertBothInterpretersSee(Workspace::class, $this->qa(1, workspace: 2), [2]);
    }

    // ----- finance -----------------------------------------------------------

    public function test_a_custom_primary_key_and_owner_column_are_honoured(): void
    {
        $this->createFinanceSchema();
        FinanceSeed::seed();

        $this->assertSame('acct_id', (new Account())->getKeyName());
        $this->assertBothInterpretersSee(Account::class, $this->context(1), FinanceSeed::ACCOUNTS_VISIBLE_TO_1);
        $this->assertBothInterpretersSee(Account::class, $this->context(3), [4]);
        $this->assertBothInterpretersSee(Account::class, $this->context(9), []);
    }

    public function test_ownership_through_a_parent_with_an_explicit_owner_key(): void
    {
        $this->createFinanceSchema();
        FinanceSeed::seed();

        $this->assertBothInterpretersSee(FeeSchedule::class, $this->context(1), FinanceSeed::FEES_VISIBLE_TO_1);

        $visible = FeeSchedule::privacyQuery($this->context(1))->get()->modelKeys();

        $this->assertNotContains(4, $visible, 'fee 4 has a NULL account key');
        $this->assertNotContains(6, $visible, 'fee 6 points at an account that does not exist');
    }

    public function test_a_revoked_grant_stops_being_a_grant(): void
    {
        $this->createFinanceSchema();
        FinanceSeed::seed();

        // Grant 2 (owner 3 -> grantee 1) is revoked, so account 4 stays hidden.
        $this->assertNotContains(4, FinanceSeed::ACCOUNTS_VISIBLE_TO_1);
        $this->assertNull(Account::privacyQuery($this->context(1))->find(4));
    }

    /** @param list<int>|null $organizations */
    private function qa(int $viewer, bool $moderator = false, ?array $organizations = [10], int $workspace = 1): \BWH\EloquentPrivacyPolicy\Context\PrivacyContext
    {
        return $this->context(
            $viewer,
            ['is_moderator' => $moderator, 'eligible_organization_ids' => $organizations ?? []],
            ['workspace_id' => $workspace],
        );
    }
}

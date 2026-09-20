<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Finance;

use Illuminate\Support\Facades\DB;

/**
 * Synthetic finance data.
 *
 * accounts visible to viewer 1:      1 (owned), 2 and 3 (grant on owner 2)
 * fee schedules visible to viewer 1: 1, 2, 5  (sum of amount = 36.00)
 */
final class FinanceSeed
{
    /** @var list<int> */
    public const array ACCOUNTS_VISIBLE_TO_1 = [1, 2, 3];

    /** @var list<int> */
    public const array FEES_VISIBLE_TO_1 = [1, 2, 5];

    public const float FEE_SUM_FOR_1 = 36.0;

    public static function seed(): void
    {
        DB::table('fx_accounts')->insert([
            ['acct_id' => 1, 'acct_owner' => 1, 'label' => 'A1', 'legacy_parent_id' => null],
            ['acct_id' => 2, 'acct_owner' => 2, 'label' => 'A2', 'legacy_parent_id' => null],
            ['acct_id' => 3, 'acct_owner' => 2, 'label' => 'A3', 'legacy_parent_id' => 1],
            ['acct_id' => 4, 'acct_owner' => 3, 'label' => 'A4', 'legacy_parent_id' => null],
            ['acct_id' => 5, 'acct_owner' => null, 'label' => 'A5', 'legacy_parent_id' => null],
        ]);

        DB::table('fx_account_grants')->insert([
            ['id' => 1, 'owner_id' => 2, 'grantee_id' => 1, 'access' => 'viewer', 'revoked_at' => null],
            ['id' => 2, 'owner_id' => 3, 'grantee_id' => 1, 'access' => 'editor', 'revoked_at' => '2026-05-01 00:00:00'],
            ['id' => 3, 'owner_id' => null, 'grantee_id' => 1, 'access' => 'viewer', 'revoked_at' => null],
            ['id' => 4, 'owner_id' => 9, 'grantee_id' => 1, 'access' => 'viewer', 'revoked_at' => null],
        ]);

        DB::table('fx_fee_schedules')->insert([
            ['id' => 1, 'acct_id' => 1, 'amount' => '10.50', 'legacy_ref' => null, 'label' => 'f1'],
            ['id' => 2, 'acct_id' => 2, 'amount' => '20.25', 'legacy_ref' => 7, 'label' => 'f2'],
            ['id' => 3, 'acct_id' => 4, 'amount' => '30.00', 'legacy_ref' => null, 'label' => 'f3'],
            ['id' => 4, 'acct_id' => null, 'amount' => '40.00', 'legacy_ref' => null, 'label' => 'f4'],
            ['id' => 5, 'acct_id' => 3, 'amount' => '5.25', 'legacy_ref' => null, 'label' => 'f5'],
            ['id' => 6, 'acct_id' => 999, 'amount' => '50.00', 'legacy_ref' => null, 'label' => 'f6'],
        ]);
    }
}

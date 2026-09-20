<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Clinical;

use Illuminate\Support\Facades\DB;

/**
 * Synthetic clinical data. All identifiers and names are invented.
 *
 * With the clock at 2026-06-01 12:00:00 UTC:
 *
 * patients visible to viewer 1: 1 (owned), 3 (active grant)
 * patients visible to viewer 2: 2, 3, 4, 5 (owned; 6 is soft-deleted)
 * records visible to viewer 1:  1, 3, 10, 11 (10 and 11 differ from the denied
 *                               status "embargoed" only in case / trailing space,
 *                               so exact string equality must let them through)
 * records visible to viewer 2:  3, 4, 5
 */
final class ClinicalSeed
{
    /** @var list<int> */
    public const array PATIENTS_VISIBLE_TO_1 = [1, 3];

    /** @var list<int> */
    public const array PATIENTS_VISIBLE_TO_2 = [2, 3, 4, 5];

    /** @var list<int> */
    public const array RECORDS_VISIBLE_TO_1 = [1, 3, 10, 11];

    /** @var list<int> */
    public const array RECORDS_VISIBLE_TO_2 = [3, 4, 5];

    public static function seed(): void
    {
        DB::table('fx_patients')->insert([
            ['id' => 1, 'owner_id' => 1, 'name' => 'P1', 'deleted_at' => null],
            ['id' => 2, 'owner_id' => 2, 'name' => 'P2', 'deleted_at' => null],
            ['id' => 3, 'owner_id' => 2, 'name' => 'P3', 'deleted_at' => null],
            ['id' => 4, 'owner_id' => 2, 'name' => 'P4', 'deleted_at' => null],
            ['id' => 5, 'owner_id' => 2, 'name' => 'P5', 'deleted_at' => null],
            ['id' => 6, 'owner_id' => 2, 'name' => 'P6', 'deleted_at' => '2026-05-02 00:00:00'],
            ['id' => 7, 'owner_id' => 1, 'name' => 'P7', 'deleted_at' => '2026-05-02 00:00:00'],
            ['id' => 8, 'owner_id' => null, 'name' => 'P8', 'deleted_at' => null],
        ]);

        DB::table('fx_patient_grants')->insert([
            // active
            ['id' => 1, 'patient_id' => 3, 'user_id' => 1, 'level' => 'reader', 'revoked_at' => null, 'expires_at' => null],
            // revoked
            ['id' => 2, 'patient_id' => 4, 'user_id' => 1, 'level' => 'manager', 'revoked_at' => '2026-05-01 00:00:00', 'expires_at' => null],
            // expired
            ['id' => 3, 'patient_id' => 5, 'user_id' => 1, 'level' => 'reader', 'revoked_at' => null, 'expires_at' => '2026-05-01 00:00:00'],
            // active, but the patient row itself is soft-deleted
            ['id' => 4, 'patient_id' => 6, 'user_id' => 1, 'level' => 'reader', 'revoked_at' => null, 'expires_at' => null],
            // someone else's grant
            ['id' => 5, 'patient_id' => 2, 'user_id' => 2, 'level' => 'manager', 'revoked_at' => null, 'expires_at' => null],
            // second, unexpired grant on the same patient
            ['id' => 6, 'patient_id' => 3, 'user_id' => 1, 'level' => 'reader', 'revoked_at' => null, 'expires_at' => '2026-07-01 00:00:00'],
            // NULL join key: matches nothing
            ['id' => 7, 'patient_id' => null, 'user_id' => 1, 'level' => 'reader', 'revoked_at' => null, 'expires_at' => null],
            // dangling parent
            ['id' => 8, 'patient_id' => 999, 'user_id' => 1, 'level' => 'reader', 'revoked_at' => null, 'expires_at' => null],
        ]);

        DB::table('fx_clinical_records')->insert([
            ['id' => 1, 'patient_id' => 1, 'review_status' => 'open', 'body' => 'r1', 'deleted_at' => null],
            ['id' => 2, 'patient_id' => 1, 'review_status' => 'embargoed', 'body' => 'r2', 'deleted_at' => null],
            ['id' => 3, 'patient_id' => 3, 'review_status' => 'open', 'body' => 'r3', 'deleted_at' => null],
            ['id' => 4, 'patient_id' => 2, 'review_status' => 'open', 'body' => 'r4', 'deleted_at' => null],
            ['id' => 5, 'patient_id' => 4, 'review_status' => 'open', 'body' => 'r5', 'deleted_at' => null],
            ['id' => 6, 'patient_id' => 6, 'review_status' => 'open', 'body' => 'r6', 'deleted_at' => null],
            ['id' => 7, 'patient_id' => 1, 'review_status' => 'open', 'body' => 'r7', 'deleted_at' => '2026-05-03 00:00:00'],
            ['id' => 8, 'patient_id' => null, 'review_status' => 'open', 'body' => 'r8', 'deleted_at' => null],
            ['id' => 9, 'patient_id' => 999, 'review_status' => 'open', 'body' => 'r9', 'deleted_at' => null],
            // exact-match bait: differs from "embargoed" only in case / trailing space
            ['id' => 10, 'patient_id' => 1, 'review_status' => 'Embargoed', 'body' => 'r10', 'deleted_at' => null],
            ['id' => 11, 'patient_id' => 1, 'review_status' => 'embargoed ', 'body' => 'r11', 'deleted_at' => null],
        ]);
    }
}

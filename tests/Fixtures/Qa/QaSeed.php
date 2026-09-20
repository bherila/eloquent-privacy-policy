<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Qa;

use Illuminate\Support\Facades\DB;

/**
 * Synthetic Q&A data. Scope is always workspace 1.
 *
 * viewer 1, not a moderator, eligible organisations [10]: 1, 2, 3, 4
 * viewer 1, not a moderator, eligible organisations []:   1, 2, 3
 * viewer 1, moderator:                                    every workspace-1 row
 */
final class QaSeed
{
    /** @var list<int> */
    public const array VISIBLE_TO_1 = [1, 2, 3, 4];

    /** @var list<int> */
    public const array VISIBLE_TO_1_NO_ORGS = [1, 2, 3];

    /** @var list<int> Privileged beats deny, but never the mandatory boundary. */
    public const array VISIBLE_TO_1_MODERATOR = [1, 2, 3, 4, 5, 6, 8, 9, 10];

    public static function seed(): void
    {
        DB::table('fx_workspaces')->insert([
            ['id' => 1, 'name' => 'W1'],
            ['id' => 2, 'name' => 'W2'],
        ]);

        $q = static fn (int $id, ?int $ws, ?int $author, ?int $org, bool $faq, bool $archived): array => [
            'id' => $id, 'workspace_id' => $ws, 'author_id' => $author, 'organization_id' => $org,
            'is_faq' => $faq, 'archived' => $archived, 'title' => "q$id",
        ];

        DB::table('fx_questions')->insert([
            $q(1, 1, 1, null, false, false),    // author
            $q(2, 1, 2, null, true, false),     // FAQ
            $q(3, 1, 2, null, false, false),    // collaborator
            $q(4, 1, 2, 10, false, false),      // eligible organisation
            $q(5, 1, 2, 20, false, false),      // ineligible organisation
            $q(6, 1, 1, null, false, true),     // author, but archived -> deny
            $q(7, 2, 1, null, false, false),    // other workspace -> mandatory Deny
            $q(8, 1, 2, null, false, false),    // nothing applies
            $q(9, 1, 2, null, true, true),      // FAQ, but archived -> deny
            $q(10, 1, null, null, false, false), // NULL author
            $q(11, null, 1, null, false, false), // NULL workspace -> mandatory Deny
        ]);

        DB::table('fx_question_collaborators')->insert([
            ['id' => 1, 'question_id' => 3, 'user_id' => 1],
            ['id' => 2, 'question_id' => 8, 'user_id' => 2],
            ['id' => 3, 'question_id' => 5, 'user_id' => null],
            ['id' => 4, 'question_id' => null, 'user_id' => 1],
            ['id' => 5, 'question_id' => 999, 'user_id' => 1],
        ]);

        DB::table('fx_audit_notes')->insert([
            ['id' => 1, 'question_id' => 1, 'note' => 'n1'],
            ['id' => 2, 'question_id' => 8, 'note' => 'n2'],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Benchmarks;

use Illuminate\Database\Connection;

/**
 * Deterministic synthetic data generator. No randomness: every row's shape is
 * a pure function of its id, so a given $totalNotes always produces the exact
 * same fixture (and the exact same visible set) on every engine.
 *
 * Notes are spread round-robin across Fixture::TENANT_COUNT tenants by id, so
 * the viewer's own tenant (Fixture::TENANT_ID) is not clustered at the front
 * of the table. Within the viewer's own tenant slice, rows cycle through 10
 * buckets exercising every grant path named in the task:
 *
 *   0 author                       -> visible (author grant)
 *   1 shared, live                 -> visible (shared grant)
 *   2 shared, expired              -> not visible (deny/grant both miss)
 *   3 own folder, live             -> visible (folder grant, via ViaParent)
 *   4 own folder, soft-deleted     -> not visible (parent folder not visible)
 *   5 author, but locked           -> not visible (deny overrides author)
 *   6 author, locked IS NULL       -> visible (NULL is not "locked = true")
 *   7 unrelated, locked IS NULL    -> not visible (no grant path)
 *   8 unrelated, unlocked          -> not visible (no grant path)
 *   9 unrelated, no folder         -> not visible (no grant path)
 *
 * Buckets 0, 1, 3, 6 are visible: 4 of 10 buckets in a tenant slice that is
 * ~10% of the total, so the viewer sees ~4% of all rows -- inside the 1-5%
 * the task asks for. The other Fixture::TENANT_COUNT - 1 tenants are pure
 * noise: every row in them is excluded by the mandatory tenant boundary alone
 * (some are even authored by the viewer, to exercise that the boundary is
 * checked before the grant stage, not instead of it).
 */
final class Seeder
{
    private const int CHUNK = 5000;

    /** @return array{tenant_slice: int, expected_visible: int, folder_pool_size: int} */
    public static function seed(Connection $connection, int $totalNotes): array
    {
        $tenantSlice = (int) round($totalNotes * Fixture::TENANT_SLICE_FRACTION);
        $otherTotal = $totalNotes - $tenantSlice;
        $otherTenants = Fixture::TENANT_COUNT - 1;
        $folderPoolSize = max(20, min(500, intdiv(max($tenantSlice, 1), 20)));

        $connection->transaction(function () use ($connection, $totalNotes, $tenantSlice, $folderPoolSize): void {
            self::seedFolders($connection, $folderPoolSize);
            self::seedNotesAndShares($connection, $totalNotes, $tenantSlice, $folderPoolSize);
        });

        $fullCycles = intdiv($tenantSlice, 10);
        $remainder = $tenantSlice % 10;
        $expectedVisible = $fullCycles * count(Fixture::VISIBLE_BUCKETS);

        foreach (Fixture::VISIBLE_BUCKETS as $bucket) {
            if ($bucket < $remainder) {
                $expectedVisible++;
            }
        }

        return [
            'tenant_slice' => $tenantSlice,
            'expected_visible' => $expectedVisible,
            'folder_pool_size' => $folderPoolSize,
            'other_tenants' => $otherTenants,
            'other_total' => $otherTotal,
        ];
    }

    private static function seedFolders(Connection $connection, int $folderPoolSize): void
    {
        $now = Fixture::NOW;
        $rows = [
            ['id' => 1, 'owner_id' => Fixture::VIEWER_ID, 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'owner_id' => Fixture::VIEWER_ID, 'deleted_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ];

        for ($i = 0; $i < $folderPoolSize; $i++) {
            $ownerId = 1000 + ($i % 37) + 1; // never the viewer, never a plausible author id
            $rows[] = ['id' => 3 + $i, 'owner_id' => $ownerId, 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $connection->table('bench_folders')->insert($chunk);
        }
    }

    private static function seedNotesAndShares(Connection $connection, int $totalNotes, int $tenantSlice, int $folderPoolSize): void
    {
        $ownLiveFolder = 1;
        $ownDeletedFolder = 2;
        $viewer = Fixture::VIEWER_ID;
        $tenant = Fixture::TENANT_ID;

        $noteBuffer = [];
        $shareBuffer = [];
        $tenantIndex = 0; // 0-based position among the viewer's own tenant rows

        for ($id = 1; $id <= $totalNotes; $id++) {
            $tenantForId = (($id - 1) % Fixture::TENANT_COUNT) + 1;

            if ($tenantForId === $tenant) {
                $bucket = $tenantIndex % 10;
                $tenantIndex++;

                $otherAuthor = 2 + ($id % 13);
                $noiseFolder = 3 + ($id % $folderPoolSize);

                [$authorId, $folderId, $locked] = match ($bucket) {
                    0 => [$viewer, $noiseFolder, false],
                    1 => [$otherAuthor, $noiseFolder, false],
                    2 => [$otherAuthor, $noiseFolder, false],
                    3 => [$otherAuthor, $ownLiveFolder, false],
                    4 => [$otherAuthor, $ownDeletedFolder, false],
                    5 => [$viewer, $noiseFolder, true],
                    6 => [$viewer, $noiseFolder, null],
                    7 => [$otherAuthor, $noiseFolder, null],
                    8 => [$otherAuthor, $noiseFolder, false],
                    default => [$otherAuthor, null, false], // bucket 9
                };

                $noteBuffer[] = [
                    'id' => $id,
                    'tenant_id' => $tenant,
                    'folder_id' => $folderId,
                    'author_id' => $authorId,
                    'locked' => $locked,
                    'title' => "bench-$id",
                    'created_at' => Fixture::NOW,
                    'updated_at' => Fixture::NOW,
                ];

                if ($bucket === 1) {
                    $shareBuffer[] = ['note_id' => $id, 'user_id' => $viewer, 'expires_at' => null];
                } elseif ($bucket === 2) {
                    $shareBuffer[] = ['note_id' => $id, 'user_id' => $viewer, 'expires_at' => Fixture::PAST];
                }
            } else {
                // Noise in someone else's tenant: excluded by the mandatory boundary
                // alone. author_id cycles through 1..50, so it sometimes equals the
                // viewer's id -- the boundary must gate that too, not just grants.
                $noteBuffer[] = [
                    'id' => $id,
                    'tenant_id' => $tenantForId,
                    'folder_id' => null,
                    'author_id' => 1 + ($id % 50),
                    'locked' => false,
                    'title' => "bench-$id",
                    'created_at' => Fixture::NOW,
                    'updated_at' => Fixture::NOW,
                ];
            }

            if (count($noteBuffer) >= self::CHUNK) {
                $connection->table('bench_notes')->insert($noteBuffer);
                $noteBuffer = [];
            }

            if (count($shareBuffer) >= self::CHUNK) {
                $connection->table('bench_note_shares')->insert($shareBuffer);
                $shareBuffer = [];
            }
        }

        if ($noteBuffer !== []) {
            $connection->table('bench_notes')->insert($noteBuffer);
        }

        if ($shareBuffer !== []) {
            $connection->table('bench_note_shares')->insert($shareBuffer);
        }
    }
}

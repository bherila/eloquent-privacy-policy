<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Matrix;

use Illuminate\Support\Facades\DB;

/**
 * Data for the predicate generator: every declared type with NULLs, empty
 * strings, case variants, trailing spaces, zero and negative integers,
 * booleans as 0/1, datetimes with and without fractional seconds, and a
 * two-level child chain containing NULL and dangling join keys.
 */
final class MatrixSeed
{
    /** @var list<int|null> */
    public const array INTS = [null, 0, -1, 1, 2, 7];

    /** @var list<string|null> */
    public const array STRINGS = [null, '', 'abc', 'Abc', 'abc ', 'zzz'];

    /** @var list<int|null> */
    public const array BOOLS = [null, 0, 1];

    /** @var list<string|null> */
    public const array DATETIMES = [null, '2026-05-01 00:00:00', '2026-06-01 12:00:00', '2026-07-01 00:00:00'];

    /** @var list<string|null> */
    public const array FRACTIONAL = [null, '2026-06-01 12:00:00', '2026-06-01 12:00:00.000000', '2026-06-01 12:00:00.500000'];

    public static function seed(): void
    {
        $rows = [];

        for ($i = 0; $i < 48; $i++) {
            $rows[] = [
                'id' => $i + 1,
                'c_int' => self::INTS[$i % 6],
                'c_int2' => self::INTS[($i + 2) % 6],
                'c_str' => self::STRINGS[intdiv($i, 2) % 6],
                'c_str2' => self::STRINGS[(intdiv($i, 3) + 1) % 6],
                'c_bool' => self::BOOLS[$i % 3],
                'c_dt' => self::DATETIMES[intdiv($i, 4) % 4],
                'c_dt2' => self::DATETIMES[($i + 1) % 4],
                'c_dtf' => self::FRACTIONAL[$i % 4],
            ];
        }

        DB::table('fx_matrix')->insert($rows);

        $parents = [null, 1, 2, 3, 5, 8, 13, 999];
        $children = [];

        for ($i = 0; $i < 32; $i++) {
            $children[] = [
                'id' => $i + 1,
                'm_id' => $parents[$i % 8],
                'k_str' => self::STRINGS[$i % 6],
                'c_int' => self::INTS[($i + 1) % 6],
                'c_str' => self::STRINGS[($i + 3) % 6],
                'c_bool' => self::BOOLS[($i + 1) % 3],
                'c_dt' => self::DATETIMES[($i + 2) % 4],
            ];
        }

        DB::table('fx_matrix_child')->insert($children);

        $grandparents = [null, 1, 2, 4, 7, 11, 999];
        $grandchildren = [];

        for ($i = 0; $i < 21; $i++) {
            $grandchildren[] = [
                'id' => $i + 1,
                'child_id' => $grandparents[$i % 7],
                'c_int' => self::INTS[$i % 6],
                'c_str' => self::STRINGS[($i + 2) % 6],
            ];
        }

        DB::table('fx_matrix_grandchild')->insert($grandchildren);
    }
}

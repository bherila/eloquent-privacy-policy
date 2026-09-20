<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures\Support;

use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use BWH\EloquentPrivacyPolicy\Runtime\PredicateEvaluator;
use BWH\EloquentPrivacyPolicy\Runtime\RelationFactLoader;
use BWH\EloquentPrivacyPolicy\Runtime\RowSnapshot;
use BWH\EloquentPrivacyPolicy\Sql\PredicateCompiler;
use Illuminate\Support\Facades\DB;

/** Runs one predicate through both interpreters over the same table. */
final class Interpreters
{
    /**
     * The ids the SQL compiler lets through.
     *
     * @return list<int>
     */
    public static function sql(string $table, Predicate $predicate, string $key = 'id'): array
    {
        $query = DB::table($table);

        (new PredicateCompiler())->apply($query, $predicate, $table);

        $ids = [];

        foreach ($query->orderBy($key)->get([$table.'.'.$key]) as $row) {
            /** @var object{id?: mixed} $row */
            $ids[] = (int) ((array) $row)[$key];
        }

        return $ids;
    }

    /**
     * The ids the runtime evaluator lets through, with facts batch-prepared
     * exactly once for the whole set of rows.
     *
     * @return list<int>
     */
    public static function runtime(string $table, Predicate $predicate, string $key = 'id'): array
    {
        $rows = self::snapshots($table, $key);
        $facts = (new RelationFactLoader(DB::connection()))->prepare($predicate, $rows);
        $evaluator = new PredicateEvaluator();

        $ids = [];

        foreach ($rows as $row) {
            if ($evaluator->evaluate($predicate, $row, $facts)) {
                $ids[] = (int) $row->get($key);
            }
        }

        return $ids;
    }

    /** @return list<RowSnapshot> */
    public static function snapshots(string $table, string $key = 'id'): array
    {
        $rows = [];

        foreach (DB::table($table)->orderBy($key)->get() as $row) {
            $rows[] = new RowSnapshot((array) $row);
        }

        return $rows;
    }
}

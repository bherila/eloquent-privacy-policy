<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Benchmarks;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * The query an application developer would hand-write for the same visibility
 * rule BenchNote::privacyPolicy() declares, for a non-staff viewer (the
 * benchmark context never sets is_staff, so the privileged stage folds to
 * never() and disappears -- see docs/contract.md §4.4 and
 * ResolvedReadPolicy::toPredicate()). With staff out of the picture and the
 * terminal defaulting to Deny, the composed predicate
 *
 *     visible = H AND ( P OR ( NOT D AND ( A OR T ) ) )
 *
 * reduces to
 *
 *     visible = H AND NOT D AND A
 *
 * i.e. tenant boundary, AND NOT locked, AND (author OR live share OR visible
 * folder). This is that reduction, written directly against the query
 * builder with no policy machinery in between -- the thing the benchmark
 * measures the package against.
 *
 * NULL handling matters here in a way that is easy to get wrong by hand:
 * `locked = 1` is NULL (neither true nor false) for a NULL `locked` column,
 * and in a SQL WHERE that behaves as "row excluded" whichever side of a NOT
 * it is on. So `WHERE NOT (locked = 1)` would silently drop the NULL-locked
 * rows the policy says must stay visible. This query spells out
 * "locked IS NULL OR locked = 0" instead, which is the two-valued semantics
 * §4.2 of the contract requires and that the package gets by construction
 * (PredicateCompiler always guards a comparison with IS NOT NULL first).
 */
final class HandwrittenQuery
{
    public static function build(Connection $connection, int $tenantId, int $viewerId, string $now): Builder
    {
        return $connection->table('bench_notes')
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $q): void {
                $q->whereNull('locked')->orWhere('locked', false);
            })
            ->where(function (Builder $q) use ($viewerId, $now): void {
                $q->where('author_id', $viewerId)
                    ->orWhereExists(function (Builder $sub) use ($viewerId, $now): void {
                        $sub->selectRaw('1')
                            ->from('bench_note_shares')
                            ->whereColumn('bench_note_shares.note_id', 'bench_notes.id')
                            ->where('bench_note_shares.user_id', $viewerId)
                            ->where(function (Builder $q2) use ($now): void {
                                $q2->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                            });
                    })
                    ->orWhereExists(function (Builder $sub) use ($viewerId): void {
                        $sub->selectRaw('1')
                            ->from('bench_folders')
                            ->whereColumn('bench_folders.id', 'bench_notes.folder_id')
                            ->where('bench_folders.owner_id', $viewerId)
                            ->whereNull('bench_folders.deleted_at');
                    });
            });
    }
}

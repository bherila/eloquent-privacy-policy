<?php

declare(strict_types=1);

/**
 * A participating grant writer: it revokes inside Privacy::withAnchors(), so it
 * takes the same anchor in the same order as the action.
 *
 * Environment: AWAIT_BEFORE_START, EMIT_BEFORE_START, EMIT_INSIDE,
 * AWAIT_INSIDE, HOLD_MS (all inside the anchored transaction).
 */

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Privacy;

require dirname(__DIR__, 2).'/vendor/autoload.php';

ChildRuntime::run(static function (ChildRuntime $runtime): array {
    $signals = $runtime->signals;
    $connection = $runtime->work();

    if (($await = ChildRuntime::env('AWAIT_BEFORE_START')) !== null) {
        $signals->await($await);
    }

    if (($emit = ChildRuntime::env('EMIT_BEFORE_START')) !== null) {
        $signals->emit($emit);
    }

    $started = microtime(true);
    $anchored = null;
    $title = null;

    Privacy::withAnchors(
        [new Anchor(Fixture::SPACES, Fixture::SPACE)],
        static function () use ($signals, $connection, $started, &$anchored, &$title): void {
            // Reached only once the anchor row is held FOR UPDATE.
            $anchored = microtime(true) - $started;
            $signals->emit('revoker_anchored');

            // What the action had done by the time the anchor came free. A
            // locking read, so this is the committed state and not a snapshot.
            $row = $connection->table(Fixture::ITEMS)->where('id', '=', Fixture::ITEM)->lockForUpdate()->first(['title']);
            $title = $row === null ? null : ((array) $row)['title'] ?? null;

            $connection->table(Fixture::GRANTS)->where('user_id', '=', Fixture::VIEWER)->delete();

            if (($emit = ChildRuntime::env('EMIT_INSIDE')) !== null) {
                $signals->emit($emit);
            }

            if (($await = ChildRuntime::env('AWAIT_INSIDE')) !== null) {
                $signals->await($await);
            }

            if (($hold = ChildRuntime::intEnv('HOLD_MS')) > 0) {
                usleep($hold * 1_000);
            }
        },
        $connection,
    );

    $signals->emit('revoker_committed');

    return [
        'title_when_anchored' => is_string($title) ? $title : null,
        'anchored_after_ms' => (int) round((float) $anchored * 1000),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
    ];
});

<?php

declare(strict_types=1);

/**
 * Runs one action in a process of its own, with its own connection, pausing
 * where the parent test told it to.
 *
 * Environment: AWAIT_BEFORE_START, EMIT_BEFORE_START (outside the
 * transaction), EMIT_IN_FACTS, AWAIT_IN_FACTS, HOLD_MS (inside it, after the
 * anchor lock and before the grant is read).
 */

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Action\ActionExecutor;
use BWH\EloquentPrivacyPolicy\Action\Receipt;
use BWH\EloquentPrivacyPolicy\Exceptions\ActionDenied;

require dirname(__DIR__, 2).'/vendor/autoload.php';

ChildRuntime::run(static function (ChildRuntime $runtime): array {
    $signals = $runtime->signals;

    if (($await = ChildRuntime::env('AWAIT_BEFORE_START')) !== null) {
        $signals->await($await);
    }

    if (($emit = ChildRuntime::env('EMIT_BEFORE_START')) !== null) {
        $signals->emit($emit);
    }

    $action = new ItemAction(
        new SignallingGrants(
            $signals,
            ChildRuntime::env('EMIT_IN_FACTS'),
            ChildRuntime::env('AWAIT_IN_FACTS'),
            ChildRuntime::intEnv('HOLD_MS'),
        ),
        static function (Receipt $receipt) use ($signals): void {
            $signals->emit('action_committed');
        },
    );

    $started = microtime(true);

    try {
        $result = (new ActionExecutor())->execute($action, Fixture::context());
        $outcome = 'allowed';
        $version = $result->receipt->version;
    } catch (ActionDenied) {
        $signals->emit('action_denied');
        $outcome = 'denied';
        $version = null;
    }

    return [
        'outcome' => $outcome,
        'version' => $version,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
    ];
});

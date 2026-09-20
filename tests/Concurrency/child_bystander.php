<?php

declare(strict_types=1);

/**
 * A grant writer that does NOT participate: the same revocation, in a
 * transaction, but without taking the anchor. Contract 6.1 claims nothing for
 * it, and this is the process that shows what "nothing" means.
 *
 * Environment: AWAIT_BEFORE_START, EMIT_BEFORE_START.
 */

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

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

    $connection->transaction(static function () use ($connection): void {
        $connection->table(Fixture::GRANTS)->where('user_id', '=', Fixture::VIEWER)->delete();
    });

    $signals->emit('bystander_committed');

    return ['elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
});

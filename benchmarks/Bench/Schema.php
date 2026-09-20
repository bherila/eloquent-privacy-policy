<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Benchmarks;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * Creates/drops the `bench_`-prefixed schema this benchmark owns. Deliberately
 * mirrors tests/Kernel/KernelSmokeTest::setUp() column-for-column (nullability,
 * types, soft deletes), plus the indexes a sane application would add — the
 * Kernel fixture is tiny and never needed them.
 */
final class Schema
{
    public const array TABLES = ['bench_note_shares', 'bench_notes', 'bench_folders'];

    public static function drop(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }
    }

    public static function create(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        self::drop($connection);

        $schema->create('bench_folders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();
            $table->softDeletes();
            $table->index('owner_id');
        });

        $schema->create('bench_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreignId('folder_id')->nullable()->constrained('bench_folders');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->boolean('locked')->nullable();
            $table->string('title');
            $table->timestamps();
            $table->index(['tenant_id', 'author_id']);
            $table->index('folder_id');
        });

        $schema->create('bench_note_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('note_id')->constrained('bench_notes');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('expires_at')->nullable();
            $table->index(['note_id', 'user_id']);
        });
    }
}

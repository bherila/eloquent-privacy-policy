<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrations-in-code for the three synthetic domains plus the helper tables the
 * predicate generator and the negative fixtures need.
 *
 * Every table is prefixed "fx_" and dropped by {@see dropFixtureTables()} only;
 * other suites running against the same database own other prefixes.
 *
 * No real foreign keys are declared on purpose: the suites deliberately store
 * dangling and NULL keys to exercise the two-valued semantics of the IR.
 */
trait FixtureSchema
{
    /** @return list<string> every fixture table, children before parents */
    protected function fixtureTables(): array
    {
        return [
            'fx_matrix_grandchild', 'fx_matrix_child', 'fx_matrix',
            'fx_fee_schedules', 'fx_account_grants', 'fx_accounts',
            'fx_question_tags', 'fx_question_collaborators', 'fx_audit_notes', 'fx_questions', 'fx_workspaces',
            'fx_clinical_records', 'fx_patient_grants', 'fx_patients',
            'fx_cycle_b', 'fx_cycle_a', 'fx_guarded', 'fx_unpoliced',
        ];
    }

    /**
     * Every test starts and ends from an empty schema. On a real engine that is
     * 36 round trips per test if each table is dropped on its own, which
     * dominates the suite's wall time, so the whole list goes in one statement.
     */
    protected function dropFixtureTables(): void
    {
        $tables = $this->fixtureTables();

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('DROP TABLE IF EXISTS '.implode(', ', array_map(
                static fn (string $table): string => '`'.$table.'`',
                $tables,
            )));

            return;
        }

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    protected function createClinicalSchema(): void
    {
        Schema::create('fx_patients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('name');
            $table->dateTime('deleted_at')->nullable();
        });

        Schema::create('fx_patient_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('level');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('fx_clinical_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('review_status');
            $table->string('body');
            $table->dateTime('deleted_at')->nullable();
        });
    }

    protected function createQaSchema(): void
    {
        Schema::create('fx_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('fx_questions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->boolean('is_faq')->default(false);
            $table->boolean('archived')->default(false);
            $table->string('title');
        });

        Schema::create('fx_question_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('question_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
        });

        Schema::create('fx_audit_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('question_id')->nullable();
            $table->string('note');
        });

        Schema::create('fx_question_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('question_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
        });
    }

    protected function createFinanceSchema(): void
    {
        Schema::create('fx_accounts', function (Blueprint $table): void {
            $table->bigIncrements('acct_id');
            $table->unsignedBigInteger('acct_owner')->nullable();
            $table->string('label');
            $table->unsignedBigInteger('legacy_parent_id')->nullable();
        });

        Schema::create('fx_account_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('grantee_id')->nullable();
            $table->string('access');
            $table->dateTime('revoked_at')->nullable();
        });

        Schema::create('fx_fee_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('acct_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('legacy_ref')->nullable();
            $table->string('label');
        });
    }

    /**
     * The table the predicate generator runs over: one nullable column per
     * declared type, a second column of the int/string types for eqCol, and a
     * two-level child chain for EXISTS and nested EXISTS.
     */
    protected function createMatrixSchema(): void
    {
        Schema::create('fx_matrix', function (Blueprint $table): void {
            $table->id();
            $table->integer('c_int')->nullable();
            $table->integer('c_int2')->nullable();
            $table->string('c_str')->nullable();
            $table->string('c_str2')->nullable();
            $table->boolean('c_bool')->nullable();
            $table->dateTime('c_dt')->nullable();
            $table->dateTime('c_dt2')->nullable();
            $table->dateTime('c_dtf', 6)->nullable();
        });

        Schema::create('fx_matrix_child', function (Blueprint $table): void {
            $table->id();
            $table->integer('m_id')->nullable();
            $table->string('k_str')->nullable();
            $table->integer('c_int')->nullable();
            $table->string('c_str')->nullable();
            $table->boolean('c_bool')->nullable();
            $table->dateTime('c_dt')->nullable();
        });

        Schema::create('fx_matrix_grandchild', function (Blueprint $table): void {
            $table->id();
            $table->integer('child_id')->nullable();
            $table->integer('c_int')->nullable();
            $table->string('c_str')->nullable();
        });
    }

    protected function createOddSchema(): void
    {
        Schema::create('fx_unpoliced', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        Schema::create('fx_guarded', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('label');
        });

        Schema::create('fx_cycle_a', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('b_id')->nullable();
            $table->string('label');
        });

        Schema::create('fx_cycle_b', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('a_id')->nullable();
            $table->string('label');
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds soft delete columns and missing performance indexes.
     * Section 2.2 (soft deletes) + Section 4.1 (missing indexes).
     */
    public function up(): void
    {
        // ── Soft Deletes ─────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('empleados', function (Blueprint $table) {
            if (!Schema::hasColumn('empleados', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_periods', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('gondola_ordenes', function (Blueprint $table) {
            if (!Schema::hasColumn('gondola_ordenes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // ── Missing Indexes (Section 4.1) ────────────────────────────────
        // users.empresa_id — filtros frecuentes por empresa
        Schema::table('users', function (Blueprint $table) {
            $table->index('empresa_id', 'users_empresa_id_idx');
        });

        // payroll_entries: filtros por periodo + empleado
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->index(
                ['payroll_period_id', 'empleado_id'],
                'payroll_entries_period_empleado_idx'
            );
        });

        // gondola_ordenes: filtros frecuentes por empresa + status
        Schema::table('gondola_ordenes', function (Blueprint $table) {
            $table->index(
                ['empresa_id', 'status'],
                'gondola_ordenes_empresa_status_idx'
            );
        });
    }

    public function down(): void
    {
        // Remove indexes
        Schema::table('gondola_ordenes', function (Blueprint $table) {
            $table->dropIndex('gondola_ordenes_empresa_status_idx');
        });

        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropIndex('payroll_entries_period_empleado_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_empresa_id_idx');
        });

        // Remove soft deletes
        Schema::table('gondola_ordenes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

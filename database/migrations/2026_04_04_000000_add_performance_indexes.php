<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tasks: filtros frecuentes por status y fecha
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['empresa_id', 'status'], 'tasks_empresa_status_idx');
            $table->index(['empresa_id', 'due_date'], 'tasks_empresa_due_date_idx');
        });

        // task_assignees: búsquedas por empleado y estado de asignación
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->index(['empresa_id', 'status'], 'task_assignees_empresa_status_idx');
            $table->index(['empresa_id', 'empleado_id'], 'task_assignees_empresa_empleado_idx');
        });

        // attendance_days: consultas diarias y semanales
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->index(['empresa_id', 'date'], 'attendance_days_empresa_date_idx');
            $table->index(['empresa_id', 'empleado_id', 'date'], 'attendance_days_empresa_empleado_date_idx');
        });

        // evidences: búsquedas por asignación
        Schema::table('evidences', function (Blueprint $table) {
            $table->index(['empresa_id', 'task_assignee_id'], 'evidences_empresa_assignee_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_empresa_status_idx');
            $table->dropIndex('tasks_empresa_due_date_idx');
        });

        Schema::table('task_assignees', function (Blueprint $table) {
            $table->dropIndex('task_assignees_empresa_status_idx');
            $table->dropIndex('task_assignees_empresa_empleado_idx');
        });

        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropIndex('attendance_days_empresa_date_idx');
            $table->dropIndex('attendance_days_empresa_empleado_date_idx');
        });

        Schema::table('evidences', function (Blueprint $table) {
            $table->dropIndex('evidences_empresa_assignee_idx');
        });
    }
};

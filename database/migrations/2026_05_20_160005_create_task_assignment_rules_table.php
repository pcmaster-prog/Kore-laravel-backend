<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignment_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('task_template_id')->constrained('task_templates')->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->nullOnDelete();

            // Quién recibe la asignación
            $table->string('assignee_type', 40); // empleado | position | section_supervisor
            $table->uuid('assignee_id')->nullable(); // empleado_id o position_id
            $table->foreignUuid('section_id')->nullable()->constrained('sections')->nullOnDelete();

            // Cuándo se asigna
            $table->jsonb('day_of_week'); // [0,1,2,3,4,5,6]
            $table->time('trigger_time')->nullable(); // ej. 08:30
            $table->string('trigger_event', 40)->default('time'); // time | attendance_checkin | both

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['empresa_id', 'is_active']);
            $table->index(['empresa_id', 'task_template_id']);
            $table->index(['empresa_id', 'assignee_type', 'assignee_id']);
            $table->index(['is_active', 'trigger_event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignment_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('task_assignee_id')->nullable()->constrained('task_assignees')->nullOnDelete();
            $table->foreignUuid('reported_by')->constrained('users')->nullOnDelete();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 40); // missing_material | broken_equipment | other
            $table->text('description');
            $table->string('status', 40)->default('open'); // open | resolved | dismissed
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'status']);
            $table->index(['empresa_id', 'task_id']);
            $table->index(['empresa_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};

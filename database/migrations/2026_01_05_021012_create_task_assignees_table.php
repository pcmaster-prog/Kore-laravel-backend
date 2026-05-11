<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('task_assignees', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
        $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();

        // estado por asignación (lo que importa para el empleado)
        $table->string('status')->default('assigned'); // assigned|in_progress|done

        $table->timestamp('started_at')->nullable();
        $table->timestamp('done_at')->nullable();

        $table->text('note')->nullable(); // nota del empleado al completar

        $table->timestamps();

        $table->unique(['task_id','empleado_id']);
        $table->index(['empresa_id','empleado_id','status']);
    });
        }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
    }
};

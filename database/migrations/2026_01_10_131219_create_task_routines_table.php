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
    Schema::create('task_routines', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignUuid('created_by')->constrained('users')->nullOnDelete();

        $table->string('name', 120);
        $table->text('description')->nullable();

        // recurrence:
        // daily -> todos los días
        // weekly -> ciertos weekdays
        $table->string('recurrence')->default('daily'); // daily|weekly

        // weekdays reales 0-6 (domingo=0) para evitar ambigüedad
        $table->jsonb('weekdays')->nullable(); // ej: [1,2,3,4,5] lunes-viernes

        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();

        $table->boolean('is_active')->default(true);

        $table->timestamps();

        $table->index(['empresa_id','is_active']);
        $table->index(['empresa_id','recurrence']);
    });
        }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_routines');
    }
};

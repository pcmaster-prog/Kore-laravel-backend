<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_schedule_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->time('current_meal_start_time')->nullable();
            $table->time('requested_meal_start_time');
            $table->integer('duration_minutes')->default(30);
            $table->text('justification');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'status']);
            $table->index(['empleado_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_schedule_change_requests');
    }
};

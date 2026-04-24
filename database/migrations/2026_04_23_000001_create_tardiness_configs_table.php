<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tardiness_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->unsignedTinyInteger('grace_period_minutes')->default(10);
            $table->unsignedTinyInteger('late_threshold_minutes')->default(1);
            $table->unsignedTinyInteger('lates_to_absence')->default(3);
            $table->enum('accumulation_period', ['week', 'biweek', 'month'])->default('month');
            $table->boolean('penalize_rest_day')->default(true);
            $table->boolean('notify_employee_on_late')->default(true);
            $table->boolean('notify_manager_on_late')->default(true);

            $table->timestamps();

            $table->unique('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tardiness_configs');
    }
};

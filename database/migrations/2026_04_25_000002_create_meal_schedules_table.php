<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('employee_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->time('meal_start_time'); // HH:mm:ss
            $table->integer('duration_minutes')->default(30);
            $table->timestamps();

            $table->unique(['employee_id', 'empresa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_schedules');
    }
};

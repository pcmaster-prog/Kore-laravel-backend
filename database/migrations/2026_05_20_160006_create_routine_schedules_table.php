<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('routine_id')->constrained('task_routines')->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->nullOnDelete();

            $table->time('trigger_time');
            $table->jsonb('trigger_days'); // [0,1,2,3,4,5,6]
            $table->boolean('auto_assign')->default(true);
            $table->boolean('notify_push')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['empresa_id', 'routine_id', 'is_active']);
            $table->index(['is_active', 'trigger_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_schedules');
    }
};

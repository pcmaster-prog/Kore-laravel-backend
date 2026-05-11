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
    Schema::create('attendance_events', function (Blueprint $table) {
        $table->uuid('id')->primary();

        $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
        $table->foreignUuid('attendance_day_id')->constrained('attendance_days')->cascadeOnDelete();

        $table->string('type'); // check_in|break_start|break_end|check_out
        $table->timestamp('occurred_at');

        $table->jsonb('meta')->nullable();

        $table->timestamps();

        $table->index(['empresa_id','attendance_day_id']);
        $table->index(['empresa_id','type']);
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};

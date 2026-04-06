<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->timestamp('lunch_start_at')->nullable()->after('last_check_out_at');
            $table->timestamp('lunch_end_at')->nullable()->after('lunch_start_at');
            // lunch_duration_minutes se calcula en el controller, no se guarda
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn(['lunch_start_at', 'lunch_end_at']);
        });
    }
};

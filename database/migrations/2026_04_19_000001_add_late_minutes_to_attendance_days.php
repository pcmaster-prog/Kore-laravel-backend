<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            // Minutos de tardanza sobre la tolerancia configurada. NULL = puntual, >0 = retardo
            $table->unsignedSmallInteger('late_minutes')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn('late_minutes');
        });
    }
};

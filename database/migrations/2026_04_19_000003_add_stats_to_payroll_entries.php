<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            // Estadísticas calculadas al generar la nómina
            $table->unsignedTinyInteger('tardiness_count')->default(0)->after('rest_days_paid');
            $table->unsignedTinyInteger('absences_count')->default(0)->after('tardiness_count');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn(['tardiness_count', 'absences_count']);
        });
    }
};

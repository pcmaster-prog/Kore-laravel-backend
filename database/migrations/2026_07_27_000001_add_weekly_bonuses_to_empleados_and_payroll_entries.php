<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bonos semanales por empleado (matriz de compensación DecorArte).
        // NULL o 0 = el bono no aplica para ese empleado; el admin decide a
        // quién se los asigna desde la ficha del usuario/empleado.
        Schema::table('empleados', function (Blueprint $table) {
            $table->decimal('attendance_bonus', 8, 2)->nullable()->after('daily_rate');
            $table->decimal('punctuality_bonus', 8, 2)->nullable()->after('attendance_bonus');
            $table->decimal('results_bonus', 8, 2)->nullable()->after('punctuality_bonus');
        });

        // Montos calculados/pagados en cada nómina semanal. El bono de
        // resultados se paga vía el bonus_amount manual existente.
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->decimal('attendance_bonus_amount', 8, 2)->default(0)->after('bonus_note');
            $table->decimal('punctuality_bonus_amount', 8, 2)->default(0)->after('attendance_bonus_amount');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['attendance_bonus', 'punctuality_bonus', 'results_bonus']);
        });

        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn(['attendance_bonus_amount', 'punctuality_bonus_amount']);
        });
    }
};

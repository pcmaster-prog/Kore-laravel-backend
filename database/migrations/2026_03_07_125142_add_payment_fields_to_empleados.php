<?php
// database/migrations/xxxx_add_payment_fields_to_empleados.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            // 'hourly' = pago por hora | 'daily' = pago por día
            $table->string('payment_type')->default('hourly')->after('daily_hours');
            $table->decimal('hourly_rate', 10, 2)->default(0)->after('payment_type');
            $table->decimal('daily_rate',  10, 2)->default(0)->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'hourly_rate', 'daily_rate']);
        });
    }
};
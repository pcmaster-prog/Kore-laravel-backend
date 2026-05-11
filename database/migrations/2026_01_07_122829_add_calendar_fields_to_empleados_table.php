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
    Schema::table('empleados', function (Blueprint $table) {
        $table->decimal('daily_hours', 5, 2)->default(8.00); // jornada “completa”
        $table->unsignedSmallInteger('rest_weekday')->nullable(); // 0-6 relativo a week_start
    });
}

public function down(): void
{
    Schema::table('empleados', function (Blueprint $table) {
        $table->dropColumn(['daily_hours','rest_weekday']);
    });
}

};

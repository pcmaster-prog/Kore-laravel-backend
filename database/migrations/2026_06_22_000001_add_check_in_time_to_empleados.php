<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->time('check_in_time')->nullable()->after('rest_weekday');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn('check_in_time');
        });
    }
};

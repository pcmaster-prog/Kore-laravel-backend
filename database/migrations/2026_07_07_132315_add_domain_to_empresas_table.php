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
        Schema::table('empresas', function (Blueprint $table) {
            // Dominio público del portal de vacantes (e.g. vacantes.decorartereposteria.mx)
            // Sirve como whitelist implícita para la resolución de tenant público por Host header.
            $table->string('domain')->nullable()->unique()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropUnique(['domain']);
            $table->dropColumn('domain');
        });
    }
};

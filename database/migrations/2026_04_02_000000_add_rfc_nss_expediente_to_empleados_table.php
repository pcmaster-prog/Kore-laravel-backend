<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('rfc')->nullable()->after('hired_at');
            $table->string('nss')->nullable()->after('rfc');
            $table->string('expediente_url')->nullable()->after('nss');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['rfc', 'nss', 'expediente_url']);
        });
    }
};

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
            if (!Schema::hasColumn('empresas', 'plan')) {
                $table->string('plan', 20)->default('starter');
            }
            if (!Schema::hasColumn('empresas', 'logo_url')) {
                $table->string('logo_url', 500)->nullable();
            }
            if (!Schema::hasColumn('empresas', 'industry')) {
                $table->string('industry', 100)->nullable();
            }
            if (!Schema::hasColumn('empresas', 'employee_count_range')) {
                $table->string('employee_count_range', 20)->nullable();
            }
            if (!Schema::hasColumn('empresas', 'allowed_ip')) {
                $table->string('allowed_ip', 45)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn([
                'plan',
                'logo_url',
                'industry',
                'employee_count_range',
                'allowed_ip'
            ]);
        });
    }
};

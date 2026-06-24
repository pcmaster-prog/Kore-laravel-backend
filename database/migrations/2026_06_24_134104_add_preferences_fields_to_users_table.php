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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notifications_enabled')->nullable()->after('avatar_url');
            $table->string('language', 5)->nullable()->default('es')->after('notifications_enabled');
            $table->string('theme', 10)->nullable()->default('system')->after('language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notifications_enabled', 'language', 'theme']);
        });
    }
};

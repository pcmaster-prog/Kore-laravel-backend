<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->boolean('lunch_pre_reminder_sent')->default(false)->after('lunch_reminder_sent');
            $table->boolean('lunch_end_reminder_sent')->default(false)->after('lunch_pre_reminder_sent');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn(['lunch_pre_reminder_sent', 'lunch_end_reminder_sent']);
        });
    }
};

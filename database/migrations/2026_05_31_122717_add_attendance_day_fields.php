<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->integer('early_departure_minutes')->nullable()->after('late_minutes');
            $table->integer('meal_overtime_minutes')->nullable()->after('early_departure_minutes');
            $table->boolean('lunch_reminder_sent')->default(false)->after('meal_overtime_minutes');
            $table->boolean('exit_reminder_sent')->default(false)->after('lunch_reminder_sent');
            $table->boolean('exit_available_sent')->default(false)->after('exit_reminder_sent');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn(['early_departure_minutes', 'meal_overtime_minutes', 'lunch_reminder_sent', 'exit_reminder_sent', 'exit_available_sent']);
        });
    }
};

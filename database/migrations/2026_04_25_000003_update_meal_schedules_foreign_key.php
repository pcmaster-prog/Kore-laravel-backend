<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_schedules', function (Blueprint $table) {
            // Drop the old foreign key constraint pointing to users
            $table->dropForeign(['employee_id']);
            
            // Add the new foreign key constraint pointing to empleados
            $table->foreign('employee_id')->references('id')->on('empleados')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('meal_schedules', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->foreign('employee_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('reviewed_at');
            $table->integer('actual_minutes')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'actual_minutes']);
        });
    }
};

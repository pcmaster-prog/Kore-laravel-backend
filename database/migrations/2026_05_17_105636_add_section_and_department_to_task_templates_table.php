<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->string('section')->nullable()->after('priority');
            $table->string('department')->nullable()->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn(['section', 'department']);
        });
    }
};

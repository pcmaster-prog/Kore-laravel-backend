<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignment_rules', function (Blueprint $table) {
            $table->foreignUuid('task_template_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('task_assignment_rules', function (Blueprint $table) {
            $table->foreignUuid('task_template_id')->nullable(false)->change();
        });
    }
};

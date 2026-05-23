<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routine_schedules', function (Blueprint $table) {
            $table->string('assignee_type', 40)->nullable()->after('notify_push');
            $table->uuid('assignee_id')->nullable()->after('assignee_type');
            $table->foreignUuid('area_id')->nullable()->after('assignee_id')->constrained('areas')->nullOnDelete();
            $table->foreignUuid('section_id')->nullable()->after('area_id')->constrained('sections')->nullOnDelete();
            $table->index(['assignee_type', 'assignee_id']);
            $table->index(['area_id']);
            $table->index(['section_id']);
        });
    }

    public function down(): void
    {
        Schema::table('routine_schedules', function (Blueprint $table) {
            $table->dropIndex(['assignee_type', 'assignee_id']);
            $table->dropIndex(['area_id']);
            $table->dropIndex(['section_id']);
            $table->dropForeign(['area_id']);
            $table->dropForeign(['section_id']);
            $table->dropColumn(['assignee_type', 'assignee_id', 'area_id', 'section_id']);
        });
    }
};

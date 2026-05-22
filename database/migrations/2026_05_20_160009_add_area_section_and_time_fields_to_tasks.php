<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('area_id')->nullable()->after('created_by')->constrained('areas')->nullOnDelete();
            $table->foreignUuid('section_id')->nullable()->after('area_id')->constrained('sections')->nullOnDelete();
            $table->integer('actual_minutes')->nullable()->after('meta');
            $table->timestamp('started_at')->nullable()->after('actual_minutes');
            $table->integer('incident_count')->default(0)->after('started_at');

            $table->index(['empresa_id', 'area_id']);
            $table->index(['empresa_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn(['actual_minutes', 'started_at', 'incident_count']);
        });
    }
};

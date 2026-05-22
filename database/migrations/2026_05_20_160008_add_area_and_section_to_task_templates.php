<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->foreignUuid('area_id')->nullable()->after('created_by')->constrained('areas')->nullOnDelete();
            $table->foreignUuid('section_id')->nullable()->after('area_id')->constrained('sections')->nullOnDelete();
            $table->boolean('voice_note_enabled')->default(false)->after('show_in_dashboard');

            $table->index(['empresa_id', 'area_id']);
            $table->index(['empresa_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn('voice_note_enabled');
        });
    }
};

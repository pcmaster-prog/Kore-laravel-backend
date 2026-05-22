<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidences', function (Blueprint $table) {
            $table->string('evidence_type', 40)->default('photo')->after('task_assignee_id');
            $table->index(['empresa_id', 'evidence_type']);
        });
    }

    public function down(): void
    {
        Schema::table('evidences', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'evidence_type']);
            $table->dropColumn('evidence_type');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->json('interview_guide_questions')->nullable()->after('scorecard_template');
        });

        Schema::table('interviews', function (Blueprint $table) {
            $table->json('document_checklist')->nullable()->after('scorecard');
        });
    }

    public function down(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->dropColumn('interview_guide_questions');
        });

        Schema::table('interviews', function (Blueprint $table) {
            $table->dropColumn('document_checklist');
        });
    }
};

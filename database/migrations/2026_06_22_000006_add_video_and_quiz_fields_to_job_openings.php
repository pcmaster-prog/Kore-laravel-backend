<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->string('induction_video_url')->nullable()->after('image_url');
            $table->json('screening_questions')->nullable()->after('induction_video_url');
            $table->unsignedTinyInteger('screening_pass_score')->default(7)->after('screening_questions');
        });
    }

    public function down(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->dropColumn(['induction_video_url', 'screening_questions', 'screening_pass_score']);
        });
    }
};

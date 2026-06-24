<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->text('about_us')->nullable()->after('requirements');
            $table->text('objective')->nullable()->after('about_us');
            $table->json('responsibilities')->nullable()->after('objective');
            $table->json('education_requirements')->nullable()->after('responsibilities');
            $table->json('experience_requirements')->nullable()->after('education_requirements');
            $table->json('knowledge_requirements')->nullable()->after('experience_requirements');
            $table->json('competencies')->nullable()->after('knowledge_requirements');
            $table->json('performance_indicators')->nullable()->after('competencies');
            $table->json('offer_details')->nullable()->after('performance_indicators');
            $table->text('closing_statement')->nullable()->after('offer_details');
        });
    }

    public function down(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->dropColumn([
                'about_us',
                'objective',
                'responsibilities',
                'education_requirements',
                'experience_requirements',
                'knowledge_requirements',
                'competencies',
                'performance_indicators',
                'offer_details',
                'closing_statement',
            ]);
        });
    }
};

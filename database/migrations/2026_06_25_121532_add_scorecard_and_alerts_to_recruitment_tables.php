<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->json('scorecard_template')->nullable()->after('screening_pass_score');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('is_rehire')->default(false)->after('manual_review_reason');
            $table->boolean('blacklist_alert')->default(false)->after('is_rehire');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->dropColumn('scorecard_template');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['is_rehire', 'blacklist_alert']);
        });
    }
};

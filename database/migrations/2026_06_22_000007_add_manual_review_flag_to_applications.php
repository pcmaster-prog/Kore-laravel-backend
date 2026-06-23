<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('manual_review_required')->default(false)->after('screening_test_results');
            $table->string('manual_review_reason')->nullable()->after('manual_review_required');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['manual_review_required', 'manual_review_reason']);
        });
    }
};

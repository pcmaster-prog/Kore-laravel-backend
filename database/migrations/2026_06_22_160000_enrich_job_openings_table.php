<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->string('location')->nullable()->after('schedule');
            $table->string('job_type')->nullable()->after('location');
            $table->string('department')->nullable()->after('job_type');
            $table->unsignedInteger('vacancies_count')->default(1)->after('department');
            $table->json('benefits')->nullable()->after('vacancies_count');
            $table->json('tags')->nullable()->after('benefits');
            $table->boolean('is_featured')->default(false)->after('tags');
            $table->timestamp('published_at')->nullable()->after('is_featured');
            $table->string('slug')->nullable()->after('published_at');

            $table->index(['empresa_id', 'status', 'published_at']);
            $table->index(['empresa_id', 'status', 'is_featured']);
            $table->index(['empresa_id', 'job_type']);
            $table->index(['empresa_id', 'department']);
            $table->index(['empresa_id', 'location']);
        });
    }

    public function down(): void
    {
        Schema::table('job_openings', function (Blueprint $table) {
            $table->dropColumn([
                'location',
                'job_type',
                'department',
                'vacancies_count',
                'benefits',
                'tags',
                'is_featured',
                'published_at',
                'slug',
            ]);
        });
    }
};

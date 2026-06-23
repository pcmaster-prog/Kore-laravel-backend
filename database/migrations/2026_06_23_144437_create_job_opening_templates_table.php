<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_opening_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('requirements')->nullable();
            $table->string('salary_range')->nullable();
            $table->string('schedule')->nullable();
            $table->string('status')->default('draft');
            $table->string('image_url')->nullable();
            $table->string('induction_video_url')->nullable();
            $table->json('screening_questions')->nullable();
            $table->unsignedTinyInteger('screening_pass_score')->default(7);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_opening_templates');
    }
};

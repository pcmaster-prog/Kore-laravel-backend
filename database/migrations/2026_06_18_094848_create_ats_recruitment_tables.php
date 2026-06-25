<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('requirements')->nullable();
            $table->string('salary_range')->nullable();
            $table->string('schedule')->nullable();
            $table->enum('status', ['draft', 'open', 'closed'])->default('open');
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('job_opening_id')->constrained('job_openings')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete(); // El aspirante
            $table->string('status')->default('new'); // new, screening, testing, interviewing, offering, hired, rejected

            // Expediente Digital / Formulario Base
            $table->json('contact_info')->nullable(); // phone, address, etc
            $table->json('education')->nullable();
            $table->json('experience')->nullable();

            // Etapas de evaluación
            $table->boolean('has_induction_video_watched')->default(false);
            $table->timestamp('induction_video_watched_at')->nullable();

            $table->json('screening_test_results')->nullable(); // Encuesta de filtrado

            // Entrevistas
            $table->timestamp('interview_scheduled_at')->nullable();
            $table->string('interview_notes')->nullable();
            $table->string('interview_result')->nullable(); // Ej: passed, failed

            $table->timestamps();
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('document_type'); // cv, id_card, comprobante_domicilio, rfc, nss, acta_nacimiento
            $table->string('file_path'); // S3 path
            $table->string('original_name')->nullable();
            $table->timestamps();
        });

        Schema::create('application_status_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete(); // Admin que lo movió
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_logs');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('job_openings');
    }
};

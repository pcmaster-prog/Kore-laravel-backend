<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_signatures', function (Blueprint $table) {
            $table->id();
            $table->morphs('receivable'); // payroll_receipt_id o gratification_receipt_id
            $table->foreignUuid('empleado_id')->constrained('empleados')->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');

            // Firma visual
            $table->text('signature_image')->nullable(); // base64 de la firma dibujada
            $table->string('signature_image_path')->nullable(); // ruta del archivo si se guarda en storage

            // Confirmación de identidad
            $table->boolean('password_verified')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Integridad del documento
            $table->string('document_hash', 64)->nullable(); // SHA-256
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_signatures');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
  Schema::create('empresa_modulos', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
    $table->foreignUuid('modulo_id')->constrained('modulos')->cascadeOnDelete();
    $table->boolean('enabled')->default(true);
    $table->jsonb('settings')->nullable();
    $table->timestamps();

    $table->unique(['empresa_id','modulo_id']);
  });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empresa_modulos');
    }
};

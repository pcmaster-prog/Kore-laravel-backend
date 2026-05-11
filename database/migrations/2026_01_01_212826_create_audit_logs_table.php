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
  Schema::create('audit_logs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
    $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('action');
    $table->string('entity_type')->nullable();
    $table->string('entity_id')->nullable();
    $table->jsonb('meta')->nullable();
    $table->timestamps();

    $table->index(['empresa_id','action']);
  });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

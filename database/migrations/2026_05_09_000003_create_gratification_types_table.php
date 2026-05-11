<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gratification_types', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('empresa_id')->nullable()->constrained('empresas')->onDelete('cascade');
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->enum('frequency', ['annual', 'biannual', 'quarterly', 'monthly', 'one_time'])->default('annual');
            $table->boolean('is_active')->default(true);
            $table->json('calculation_rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gratification_types');
    }
};

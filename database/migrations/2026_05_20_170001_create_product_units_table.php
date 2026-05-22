<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('abbreviation', 20)->nullable();
            $table->decimal('conversion_to_default', 10, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['empresa_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};

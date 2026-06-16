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
        Schema::create('empleado_modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('empleado_id')->constrained('empleados')->cascadeOnDelete();
            $table->string('module_slug');
            $table->timestamps();

            $table->unique(['empleado_id', 'module_slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleado_modulos');
    }
};

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
        Schema::create('module_position', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('position_id')->constrained('positions')->cascadeOnDelete();
            $table->string('module_slug');
            $table->timestamps();

            $table->unique(['position_id', 'module_slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_position');
    }
};

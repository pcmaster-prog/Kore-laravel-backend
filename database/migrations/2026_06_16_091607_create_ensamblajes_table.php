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
        Schema::create('ensamblajes', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('producto_id')->constrained('producto_maderas');
            $table->integer('cantidad_bolsas');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ensamblajes');
    }
};

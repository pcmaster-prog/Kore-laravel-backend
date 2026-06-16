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
        Schema::create('registro_produccions', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->foreignId('producto_id')->constrained('producto_maderas');
            $table->integer('cantidad');
            $table->foreignUuid('user_id')->constrained('users');
            $table->text('notas')->nullable();
            $table->boolean('anulado')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registro_produccions');
    }
};

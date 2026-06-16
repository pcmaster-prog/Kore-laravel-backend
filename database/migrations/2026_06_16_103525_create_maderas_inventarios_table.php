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
        Schema::create('maderas_inventarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_id')->constrained('maderas_catalogos')->onDelete('cascade');
            $table->integer('stock')->default(0);
            $table->integer('stock_minimo')->default(0);
            $table->enum('status', ['ok', 'low', 'critical'])->default('ok');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maderas_inventarios');
    }
};

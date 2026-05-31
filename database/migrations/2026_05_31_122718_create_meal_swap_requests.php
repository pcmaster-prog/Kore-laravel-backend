<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_swap_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('empresa_id');
            $table->uuid('solicitante_id');
            $table->uuid('receptor_id');
            $table->date('fecha');
            $table->string('status')->default('pending'); // pending, accepted, approved, rejected
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'status']);
            $table->index(['solicitante_id', 'fecha']);
            $table->index(['receptor_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_swap_requests');
    }
};

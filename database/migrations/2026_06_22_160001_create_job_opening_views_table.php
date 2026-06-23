<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_opening_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('job_opening_id')->constrained('job_openings')->cascadeOnDelete();
            $table->string('source')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['job_opening_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_opening_views');
    }
};

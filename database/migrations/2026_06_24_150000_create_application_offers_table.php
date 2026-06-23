<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->decimal('salary', 12, 2);
            $table->unsignedTinyInteger('trial_months')->default(1);
            $table->string('status')->default('draft'); // draft | sent | accepted | rejected
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_offers');
    }
};

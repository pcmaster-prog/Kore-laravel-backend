<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignment_rule_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignUuid('rule_id')->constrained('task_assignment_rules')->cascadeOnDelete();
            $table->foreignUuid('template_id')->constrained('task_templates')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['rule_id', 'template_id']);
            $table->index(['empresa_id', 'rule_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignment_rule_items');
    }
};

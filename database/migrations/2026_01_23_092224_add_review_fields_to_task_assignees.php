<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            // Estado de revisión: none | pending | approved | rejected
            $table->string('review_status')->default('none')->after('status');

            // Momento en que el empleado marca la tarea como completada (envía a revisión)
            $table->timestamp('submitted_at')->nullable()->after('done_at');

            // Momento en que un supervisor/admin revisa (aprueba o rechaza)
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');

            // Usuario que realiza la revisión (admin/supervisor)
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('reviewed_at');

            // Comentario opcional del revisor
            $table->text('review_note')->nullable()->after('reviewed_by');

            // Índice para optimizar consultas por empresa y estado de revisión
            $table->index(['empresa_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::table('task_assignees', function (Blueprint $table) {
            // Eliminar índice
            $table->dropIndex(['empresa_id', 'review_status']);

            // Eliminar columnas añadidas
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'review_status',
                'submitted_at',
                'reviewed_at',
                'reviewed_by',
                'review_note'
            ]);
        });
    }
};
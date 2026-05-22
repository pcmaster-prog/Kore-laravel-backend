<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('gondola_orden_id')
                ->nullable()
                ->after('section_id')
                ->constrained('gondola_ordenes')
                ->nullOnDelete();

            $table->string('task_source', 40)
                ->nullable()
                ->after('gondola_orden_id');

            $table->index(['empresa_id', 'gondola_orden_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'gondola_orden_id']);
            $table->dropConstrainedForeignId('gondola_orden_id');
            $table->dropColumn('task_source');
        });
    }
};

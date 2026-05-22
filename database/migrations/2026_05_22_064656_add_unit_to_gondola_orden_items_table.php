<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gondola_orden_items', function (Blueprint $table) {
            $table->string('unit', 40)
                ->nullable()
                ->after('unidad');
        });
    }

    public function down(): void
    {
        Schema::table('gondola_orden_items', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};

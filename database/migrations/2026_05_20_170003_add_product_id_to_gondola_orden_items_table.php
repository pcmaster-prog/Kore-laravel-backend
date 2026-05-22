<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gondola_orden_items', function (Blueprint $table) {
            $table->foreignUuid('product_id')
                ->nullable()
                ->after('gondola_producto_id')
                ->constrained('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gondola_orden_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gondola_productos', function (Blueprint $table) {
            $table->foreignUuid('product_id')
                ->nullable()
                ->after('gondola_id')
                ->constrained('products')
                ->nullOnDelete();

            $table->unique(['gondola_id', 'product_id'], 'idx_gondola_productos_gondola_product_unique');
        });
    }

    public function down(): void
    {
        Schema::table('gondola_productos', function (Blueprint $table) {
            $table->dropUnique('idx_gondola_productos_gondola_product_unique');
            $table->dropConstrainedForeignId('product_id');
        });
    }
};

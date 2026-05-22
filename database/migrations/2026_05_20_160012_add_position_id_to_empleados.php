<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->foreignUuid('position_id')->nullable()->after('user_id')->constrained('positions')->nullOnDelete();
            $table->index(['empresa_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'position_id']);
            $table->dropConstrainedForeignId('position_id');
        });
    }
};

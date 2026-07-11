<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            // Status individual por entrada: draft (editable) o locked (cerrada/aprobada)
            $table->string('status', 20)->default('draft')->after('total');
            $table->timestamp('locked_at')->nullable()->after('status');
            $table->uuid('locked_by')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn(['status', 'locked_at', 'locked_by']);
        });
    }
};

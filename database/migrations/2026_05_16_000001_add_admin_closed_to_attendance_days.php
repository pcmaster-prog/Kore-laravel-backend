<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->boolean('admin_closed')->default(false)->after('status');
            $table->uuid('admin_closed_by')->nullable()->after('admin_closed');
            $table->text('admin_closed_reason')->nullable()->after('admin_closed_by');

            $table->foreign('admin_closed_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropForeign(['admin_closed_by']);
            $table->dropColumn(['admin_closed', 'admin_closed_by', 'admin_closed_reason']);
        });
    }
};

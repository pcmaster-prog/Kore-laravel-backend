<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};

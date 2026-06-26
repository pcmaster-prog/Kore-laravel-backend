<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pesaje_sabors', function (Blueprint $table) {
            if (! Schema::hasColumn('pesaje_sabors', 'peso_estandar')) {
                $table->decimal('peso_estandar', 10, 2)->default(20.00)->after('presentacion');
            }
            if (! Schema::hasColumn('pesaje_sabors', 'unidad')) {
                $table->string('unidad', 50)->default('bulto')->after('peso_estandar');
            }
            if (! Schema::hasColumn('pesaje_sabors', 'empresa_id')) {
                $table->foreignUuid('empresa_id')->nullable()->after('unidad')->constrained('empresas')->nullOnDelete();
            }
            if (! Schema::hasColumn('pesaje_sabors', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Valores por defecto para registros existentes
        DB::table('pesaje_sabors')->whereNull('peso_estandar')->update(['peso_estandar' => 20.00]);
        DB::table('pesaje_sabors')->whereNull('unidad')->orWhere('unidad', '')->update(['unidad' => 'bulto']);

        Schema::table('pesaje_registros', function (Blueprint $table) {
            if (! Schema::hasColumn('pesaje_registros', 'cantidad')) {
                $table->decimal('cantidad', 10, 2)->default(1)->after('sabor_id');
            }
            if (! Schema::hasColumn('pesaje_registros', 'empresa_id')) {
                $table->foreignUuid('empresa_id')->nullable()->after('cantidad')->constrained('empresas')->nullOnDelete();
            }
            if (! Schema::hasColumn('pesaje_registros', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('pesaje_registros')->whereNull('cantidad')->update(['cantidad' => 1]);

        // Backfill empresa_id desde el empleado relacionado
        $registrosSinEmpresa = DB::table('pesaje_registros')
            ->whereNull('empresa_id')
            ->whereNotNull('empleado_id')
            ->select('id', 'empleado_id')
            ->get();

        foreach ($registrosSinEmpresa as $registro) {
            $empresaId = DB::table('empleados')->where('id', $registro->empleado_id)->value('empresa_id');
            if ($empresaId) {
                DB::table('pesaje_registros')->where('id', $registro->id)->update(['empresa_id' => $empresaId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pesaje_sabors', function (Blueprint $table) {
            $table->dropColumnIfExists('peso_estandar');
            $table->dropColumnIfExists('unidad');
            $table->dropColumnIfExists('deleted_at');

            if (Schema::hasColumn('pesaje_sabors', 'empresa_id')) {
                $table->dropForeign(['empresa_id']);
                $table->dropColumn('empresa_id');
            }
        });

        Schema::table('pesaje_registros', function (Blueprint $table) {
            $table->dropColumnIfExists('cantidad');
            $table->dropColumnIfExists('deleted_at');

            if (Schema::hasColumn('pesaje_registros', 'empresa_id')) {
                $table->dropForeign(['empresa_id']);
                $table->dropColumn('empresa_id');
            }
        });
    }
};

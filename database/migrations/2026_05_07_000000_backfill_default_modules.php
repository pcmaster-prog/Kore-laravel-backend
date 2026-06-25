<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $defaultModules = ['tareas', 'asistencia', 'nomina', 'configuracion', 'gondolas', 'semaforo'];
        $empresas = DB::table('empresas')->pluck('id');

        foreach ($empresas as $empresaId) {
            $existing = DB::table('empresa_modules')
                ->where('empresa_id', $empresaId)
                ->pluck('module_slug')
                ->toArray();

            $missing = array_diff($defaultModules, $existing);

            foreach ($missing as $mod) {
                DB::table('empresa_modules')->insert([
                    'id' => (string) Str::uuid(),
                    'empresa_id' => $empresaId,
                    'module_slug' => $mod,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // No reversible safely — modules could have been added legitimately later
    }
};

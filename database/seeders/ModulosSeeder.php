<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Modulo;

class ModulosSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['key'=>'employees','name'=>'Empleados','is_premium'=>false],
            ['key'=>'attendance','name'=>'Asistencia','is_premium'=>false],
            ['key'=>'tasks','name'=>'Tareas','is_premium'=>false],
            ['key'=>'evidences','name'=>'Evidencias','is_premium'=>false],
            ['key'=>'reports_basic','name'=>'Reportes básicos','is_premium'=>false],
            ['key'=>'metrics_advanced','name'=>'Métricas avanzadas','is_premium'=>true],
            ['key'=>'reports_advanced','name'=>'Reportes avanzados','is_premium'=>true],
        ];

        foreach ($items as $it) {
            Modulo::updateOrCreate(['key'=>$it['key']], $it);
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BitacoraCriteriosSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['label' => 'Llegó tarde',          'tipo' => 'negativo', 'sort_order' => 1],
            ['label' => 'Estuvo en el celular',  'tipo' => 'negativo', 'sort_order' => 2],
            ['label' => 'Platicó en exceso',     'tipo' => 'negativo', 'sort_order' => 3],
            ['label' => 'Completó tareas',       'tipo' => 'positivo', 'sort_order' => 4],
            ['label' => 'Actitud proactiva',     'tipo' => 'positivo', 'sort_order' => 5],
        ];

        foreach ($items as $item) {
            DB::table('bitacora_criterios')->updateOrInsert(
                ['label' => $item['label'], 'tipo' => $item['tipo']],
                array_merge($item, [
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}

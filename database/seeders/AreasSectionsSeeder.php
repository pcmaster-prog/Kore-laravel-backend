<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Area;
use App\Models\Section;
use App\Models\Empresa;

class AreasSectionsSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = Empresa::first();
        if (!$empresa) {
            $this->command->warn('No hay empresas creadas. Saltando seeder de áreas/secciones.');
            return;
        }

        $areasData = [
            [
                'name' => 'Patio',
                'icon' => 'Sun',
                'sections' => ['General'],
            ],
            [
                'name' => 'Mostrador',
                'icon' => 'Store',
                'sections' => ['Atención', 'Exhibición'],
            ],
            [
                'name' => 'Almacén',
                'icon' => 'Warehouse',
                'sections' => ['Inventario', 'Recepción'],
            ],
            [
                'name' => 'Caja',
                'icon' => 'CreditCard',
                'sections' => ['Cobro'],
            ],
            [
                'name' => 'Producción',
                'icon' => 'Factory',
                'sections' => ['Preparación'],
            ],
        ];

        foreach ($areasData as $index => $areaData) {
            $area = Area::create([
                'empresa_id' => $empresa->id,
                'name' => $areaData['name'],
                'icon' => $areaData['icon'],
                'sort_order' => $index + 1,
                'is_active' => true,
            ]);

            foreach ($areaData['sections'] as $sIndex => $sectionName) {
                Section::create([
                    'empresa_id' => $empresa->id,
                    'area_id' => $area->id,
                    'name' => $sectionName,
                    'sort_order' => $sIndex + 1,
                    'is_active' => true,
                ]);
            }
        }

        $this->command->info("Áreas y secciones de demo creadas para empresa: {$empresa->name}");
    }
}

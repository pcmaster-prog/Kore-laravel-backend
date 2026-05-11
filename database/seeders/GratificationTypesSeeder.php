<?php

namespace Database\Seeders;

use App\Models\GratificationType;
use Illuminate\Database\Seeder;

class GratificationTypesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'code' => 'AGUINALDO',
                'name' => 'Aguinaldo Anual',
                'description' => 'Aguinaldo correspondiente al ejercicio fiscal',
                'frequency' => 'annual',
                'calculation_rules' => ['min_days' => 365, 'taxable_percentage' => 1.0],
            ],
            [
                'code' => 'BONO',
                'name' => 'Bono de Productividad',
                'description' => 'Bono por cumplimiento de metas',
                'frequency' => 'one_time',
                'calculation_rules' => ['min_days' => 90, 'taxable_percentage' => 1.0],
            ],
            [
                'code' => 'PTU',
                'name' => 'Participación de Utilidades',
                'description' => 'Participación de utilidades del ejercicio fiscal',
                'frequency' => 'annual',
                'calculation_rules' => ['min_days' => 365, 'taxable_percentage' => 1.0],
            ],
            [
                'code' => 'BONO_ANTIG',
                'name' => 'Bono por Antigüedad',
                'description' => 'Bono por antigüedad en la empresa',
                'frequency' => 'annual',
                'calculation_rules' => ['min_days' => 365, 'taxable_percentage' => 1.0],
            ],
        ];

        foreach ($defaults as $item) {
            GratificationType::firstOrCreate(
                ['code' => $item['code']],
                array_merge($item, [
                    'empresa_id' => null, // Se asignará al crear la empresa o manualmente
                    'is_active' => true,
                ])
            );
        }
    }
}

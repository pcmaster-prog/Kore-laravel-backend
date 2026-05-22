<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductUnitsSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = DB::table('empresas')->first();

        if (! $empresa) {
            return;
        }

        $units = [
            ['name' => 'pieza', 'abbreviation' => 'pz'],
            ['name' => 'caja', 'abbreviation' => 'cja'],
            ['name' => 'media caja', 'abbreviation' => '1/2'],
            ['name' => 'kilogramo', 'abbreviation' => 'kg'],
            ['name' => 'litro', 'abbreviation' => 'lt'],
        ];

        foreach ($units as $unit) {
            DB::table('product_units')->insert([
                'id'         => (string) Str::uuid(),
                'empresa_id' => $empresa->id,
                'name'       => $unit['name'],
                'abbreviation' => $unit['abbreviation'],
                'conversion_to_default' => null,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

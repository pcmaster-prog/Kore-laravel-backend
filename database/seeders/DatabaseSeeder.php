<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Catálogos globales del sistema
        $this->call([
            ModulosSeeder::class,
            BitacoraCriteriosSeeder::class,
        ]);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\JobOpening;
use App\Models\Empresa;

class JobOpeningSeeder extends Seeder
{
    public function run(): void
    {
        $slug = config('app.default_empresa_slug') ?? 'DecorArte';

        $empresa = Empresa::where('slug', $slug)
            ->orWhereRaw('LOWER(slug) = LOWER(?)', [$slug])
            ->first();

        if (! $empresa) {
            $empresa = Empresa::first();
        }

        if (! $empresa) {
            $this->command->info('No hay empresa, saltando JobOpeningSeeder');
            return;
        }

        JobOpening::create([
            'empresa_id' => $empresa->id,
            'title' => 'Ayudante de Repostería',
            'description' => 'Apoyo general en la preparación de pasteles, limpieza del área y empaquetado.',
            'requirements' => ['Disponibilidad de horario', 'Gusto por la repostería', 'Trabajo en equipo'],
            'salary_range' => '$1,500 - $1,800 semanales',
            'schedule' => 'Lunes a Sábado 8:00am - 4:00pm',
            'status' => 'open'
        ]);

        JobOpening::create([
            'empresa_id' => $empresa->id,
            'title' => 'Decorador(a) de Pasteles',
            'description' => 'Decoración de pasteles de línea y personalizados con fondant y buttercream.',
            'requirements' => ['Experiencia mínima de 1 año', 'Creatividad', 'Atención al detalle'],
            'salary_range' => '$2,000 - $2,500 semanales',
            'schedule' => 'Lunes a Sábado 9:00am - 5:00pm',
            'status' => 'open'
        ]);
        
        JobOpening::create([
            'empresa_id' => $empresa->id,
            'title' => 'Atención en Mostrador',
            'description' => 'Atención a clientes, cobro en caja, entrega de pedidos y limpieza del local.',
            'requirements' => ['Facilidad de palabra', 'Manejo de caja', 'Excelente presentación'],
            'salary_range' => '$1,600 - $1,900 semanales',
            'schedule' => 'Lunes a Domingo con 1 día de descanso',
            'status' => 'open'
        ]);
    }
}

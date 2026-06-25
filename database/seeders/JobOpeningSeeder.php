<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\JobOpening;
use Illuminate\Database\Seeder;

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
            'about_us' => 'Somos una repostería artesanal enfocada en crear experiencias dulces memorables para nuestros clientes.',
            'objective' => 'Apoyar en la preparación de productos de repostería, manteniendo altos estándares de higiene y calidad.',
            'responsibilities' => [
                'Preparar masas y cremas básicas según las recetas establecidas.',
                'Mantener limpio y ordenado el área de trabajo.',
                'Empacar productos terminados de manera higiénica.',
                'Apoyar en el inventario de insumos y materia prima.',
            ],
            'education_requirements' => [
                'Secundaria terminada.',
                'Carrera técnica en panadería/repostería (deseable).',
            ],
            'experience_requirements' => [
                'Experiencia previa no indispensable.',
                'Conocimiento básico de cocina (valorado).',
            ],
            'knowledge_requirements' => [
                'Manejo básico de utensilios de cocina.',
                'Normas de higiene alimentaria.',
                'Pesaje y medición de ingredientes.',
            ],
            'competencies' => [
                'Trabajo en equipo.',
                'Proactividad.',
                'Atención al detalle.',
                'Puntualidad.',
            ],
            'performance_indicators' => [
                'Cumplimiento de recetas.',
                'Limpieza del área de trabajo.',
                'Tiempo de preparación.',
                'Merma controlada.',
            ],
            'offer_details' => [
                'Salario: $1,500 - $1,800 semanales.',
                'Horario: Lunes a Sábado 8:00am - 4:00pm.',
                'Capacitación pagada.',
                'Ambiente familiar.',
            ],
            'closing_statement' => 'Si te apasiona la repostería y quieres crecer con nosotros, ¡postúlate!',
            'salary_range' => '$1,500 - $1,800 semanales',
            'schedule' => 'Lunes a Sábado 8:00am - 4:00pm',
            'status' => 'open',
        ]);

        JobOpening::create([
            'empresa_id' => $empresa->id,
            'title' => 'Decorador(a) de Pasteles',
            'description' => 'Decoración de pasteles de línea y personalizados con fondant y buttercream.',
            'requirements' => ['Experiencia mínima de 1 año', 'Creatividad', 'Atención al detalle'],
            'about_us' => 'En nuestra repostería creamos pasteles únicos para celebraciones especiales, combinando arte y sabor.',
            'objective' => 'Decorar pasteles de línea y personalizados cumpliendo con los estándares estéticos y de calidad de la marca.',
            'responsibilities' => [
                'Decorar pasteles con fondant y buttercream.',
                'Diseñar propuestas personalizadas para clientes.',
                'Cumplir con pedidos en tiempo y forma.',
                'Mantener herramientas de decoración limpias y organizadas.',
            ],
            'education_requirements' => [
                'Preparatoria terminada.',
                'Curso profesional de decoración de pasteles.',
            ],
            'experience_requirements' => [
                'Mínimo 1 año de experiencia en decoración de pasteles.',
                'Portafolio de trabajos previos.',
            ],
            'knowledge_requirements' => [
                'Técnicas de buttercream y fondant.',
                'Paleta de colores y diseño.',
                'Conservación de productos terminados.',
            ],
            'competencies' => [
                'Creatividad.',
                'Atención al detalle.',
                'Gestión del tiempo.',
                'Comunicación con clientes.',
            ],
            'performance_indicators' => [
                'Calidad visual del producto.',
                'Satisfacción del cliente.',
                'Cumplimiento de tiempos de entrega.',
                'Innovación en diseños.',
            ],
            'offer_details' => [
                'Salario: $2,000 - $2,500 semanales.',
                'Horario: Lunes a Sábado 9:00am - 5:00pm.',
                'Material de trabajo incluido.',
                'Bonos por desempeño.',
            ],
            'closing_statement' => 'Únete a nuestro equipo y convierte tu talento en pasteles inolvidables.',
            'salary_range' => '$2,000 - $2,500 semanales',
            'schedule' => 'Lunes a Sábado 9:00am - 5:00pm',
            'status' => 'open',
        ]);

        JobOpening::create([
            'empresa_id' => $empresa->id,
            'title' => 'Atención en Mostrador',
            'description' => 'Atención a clientes, cobro en caja, entrega de pedidos y limpieza del local.',
            'requirements' => ['Facilidad de palabra', 'Manejo de caja', 'Excelente presentación'],
            'about_us' => 'Buscamos personas cálidas y responsables para ser la cara de nuestra repostería frente a los clientes.',
            'objective' => 'Brindar una excelente experiencia de atención al cliente en mostrador, cobro y entrega de pedidos.',
            'responsibilities' => [
                'Atender a clientes con amabilidad y calidez.',
                'Realizar cobros en caja de forma precisa.',
                'Entregar pedidos de manera correcta y oportuna.',
                'Mantener limpio el área de mostrador.',
            ],
            'education_requirements' => [
                'Secundaria terminada.',
                'Preparatoria (deseable).',
            ],
            'experience_requirements' => [
                'Experiencia en atención al cliente o caja (valorada).',
                'Manejo de punto de venta (deseable).',
            ],
            'knowledge_requirements' => [
                'Manejo básico de caja.',
                'Conocimiento de productos de repostería.',
                'Manejo de conflictos básicos.',
            ],
            'competencies' => [
                'Facilidad de palabra.',
                'Excelente presentación.',
                'Orientación al cliente.',
                'Honestidad.',
            ],
            'performance_indicators' => [
                'Satisfacción del cliente.',
                'Exactitud en cobros.',
                'Presentación personal.',
                'Cumplimiento de horarios.',
            ],
            'offer_details' => [
                'Salario: $1,600 - $1,900 semanales.',
                'Horario: Lunes a Domingo con 1 día de descanso.',
                'Propinas.',
                'Capacitación en servicio al cliente.',
            ],
            'closing_statement' => 'Si disfrutas atender a las personas, queremos conocerte.',
            'salary_range' => '$1,600 - $1,900 semanales',
            'schedule' => 'Lunes a Domingo con 1 día de descanso',
            'status' => 'open',
        ]);
    }
}

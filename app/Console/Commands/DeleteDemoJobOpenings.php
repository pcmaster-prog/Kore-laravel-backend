<?php

namespace App\Console\Commands;

use App\Models\JobOpening;
use Illuminate\Console\Command;

class DeleteDemoJobOpenings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kore:delete-demo-jobs
                            {--with-applications : También elimina las postulaciones asociadas a estas vacantes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elimina las vacantes de prueba creadas por JobOpeningSeeder.';

    /**
     * Títulos de las vacantes demo del seeder.
     */
    private const DEMO_TITLES = [
        'Ayudante de Repostería',
        'Decorador(a) de Pasteles',
        'Atención en Mostrador',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $withApplications = $this->option('with-applications') ?: $this->confirm(
            '¿También elimino las postulaciones asociadas a estas vacantes?',
            true
        );

        $jobs = JobOpening::whereIn('title', self::DEMO_TITLES)->get();

        if ($jobs->isEmpty()) {
            $this->warn('No se encontraron vacantes de prueba.');
            return self::SUCCESS;
        }

        $deletedJobs = 0;
        $deletedApplications = 0;

        foreach ($jobs as $job) {
            if ($withApplications) {
                $count = $job->applications()->count();
                $job->applications()->delete();
                $deletedApplications += $count;
            }

            $job->delete();
            $deletedJobs++;
            $this->info("Eliminada: {$job->title}");
        }

        $this->newLine();
        $this->info("Resumen:");
        $this->info("  Vacantes eliminadas: {$deletedJobs}");
        if ($withApplications) {
            $this->info("  Postulaciones eliminadas: {$deletedApplications}");
        }

        return self::SUCCESS;
    }
}

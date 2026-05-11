<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Evidence;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class MigrateEvidenciasToR2 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'evidencias:migrate-to-r2';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing local public evidences to Cloudflare R2 (S3)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetDisk = config('filesystems.default', 's3');
        
        $this->info("Iniciando migración de 'public' a '{$targetDisk}'...");

        $evidencias = Evidence::where('disk', 'public')->get();

        if ($evidencias->isEmpty()) {
            $this->info('No hay evidencias en el disco public para migrar.');
            return;
        }

        $this->withProgressBar($evidencias, function (Evidence $e) use ($targetDisk) {
            if ($e->path && Storage::disk('public')->exists($e->path)) {
                $fileContents = Storage::disk('public')->get($e->path);
                
                try {
                    Storage::disk($targetDisk)->put($e->path, $fileContents);
                    
                    DB::table('evidences')
                        ->where('id', $e->id)
                        ->update(['disk' => $targetDisk]);

                } catch (\Exception $ex) {
                    $this->error("\nError migrando {$e->id}: " . $ex->getMessage());
                }
            } else {
                $this->warn("\nArchivo no encontrado en public para evidencia {$e->id}: {$e->path}");
            }
        });

        $this->newLine();
        $this->info('Migración completada.');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Convierte URLs públicas de S3 almacenadas en empresas.documentos
     * a la tupla disk+path, eliminando la URL pública de la base de datos.
     */
    public function up(): void
    {
        $bucket = config('filesystems.disks.s3.bucket');

        DB::table('empresas')
            ->whereNotNull('documentos')
            ->orderBy('id')
            ->chunkById(100, function ($empresas) use ($bucket) {
                foreach ($empresas as $empresa) {
                    $documentos = json_decode($empresa->documentos, true) ?? [];
                    if (! is_array($documentos) || $documentos === []) {
                        continue;
                    }

                    $changed = false;

                    foreach ($documentos as $index => $doc) {
                        // Si ya tiene path y disk, solo aseguramos que no haya url pública.
                        if (! empty($doc['path']) && ! empty($doc['disk'])) {
                            unset($documentos[$index]['url']);
                            $changed = true;

                            continue;
                        }

                        // Intentar extraer path desde una URL pública antigua.
                        if (! empty($doc['url'])) {
                            $path = $this->extractS3Path($doc['url'], $bucket);

                            if ($path) {
                                $documentos[$index]['path'] = $path;
                                $documentos[$index]['disk'] = 's3';
                            } else {
                                Log::warning("No se pudo extraer path de URL pública para empresa {$empresa->id}", [
                                    'url' => $doc['url'],
                                ]);
                                $documentos[$index]['path'] = null;
                                $documentos[$index]['disk'] = 's3';
                            }

                            unset($documentos[$index]['url']);
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        DB::table('empresas')
                            ->where('id', $empresa->id)
                            ->update([
                                'documentos' => json_encode(array_values($documentos)),
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // No se puede reconstruir una URL pública sin conocer la política del bucket.
        // Los documentos permanecen con disk+path.
    }

    /**
     * Extrae la clave (path) dentro del bucket a partir de una URL pública de S3.
     * Soporta formatos virtual-hosted y path-style.
     */
    private function extractS3Path(string $url, ?string $bucket): ?string
    {
        $parsed = parse_url($url);
        if (! $parsed || ! isset($parsed['path'])) {
            return null;
        }

        $path = ltrim($parsed['path'], '/');

        if ($bucket && str_starts_with($path, $bucket.'/')) {
            return substr($path, strlen($bucket) + 1);
        }

        return $path ?: null;
    }
};

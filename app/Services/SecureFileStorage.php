<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Servicio centralizado para almacenar y servir archivos de forma privada.
 *
 * Nunca expone URLs públicas. Todos los archivos se suben a un disco privado
 * (S3 con ACL privado o disco local privado) y se sirven mediante URLs firmadas.
 */
class SecureFileStorage
{
    /**
     * Discos nunca permitidos por seguridad.
     */
    private const FORBIDDEN_DISKS = ['public'];

    /**
     * Disco privado por defecto cuando S3 no está configurado.
     */
    private const FALLBACK_DISK = 'local';

    /**
     * Disco S3 privado.
     */
    private const S3_PRIVATE_DISK = 's3_private';

    /**
     * Expiración por defecto de URLs firmadas (minutos).
     */
    private const DEFAULT_URL_EXPIRATION_MINUTES = 30;

    /**
     * Determina el disco privado activo.
     *
     * Prioriza S3 si tiene credenciales configuradas; de lo contrario usa
     * el disco local privado.
     */
    public static function disk(): string
    {
        $hasS3 = config('filesystems.disks.s3_private.key')
            && config('filesystems.disks.s3_private.secret')
            && config('filesystems.disks.s3_private.bucket');

        return $hasS3 ? self::S3_PRIVATE_DISK : self::FALLBACK_DISK;
    }

    /**
     * Sube un archivo al disco privado.
     *
     * @param  UploadedFile  $file  Archivo a subir.
     * @param  string  $folder  Carpeta destino dentro del disco (ej: "applications/123").
     * @param  array<int,string>  $allowedMimeTypes  Tipos MIME permitidos (vacío = cualquiera).
     * @param  int  $maxSizeKb  Tamaño máximo en KB.
     * @return object{disk:string,path:string,original_name:string,mime:string,size:int}
     *
     * @throws InvalidArgumentException Si el archivo no pasa validación.
     */
    public static function upload(
        UploadedFile $file,
        string $folder,
        array $allowedMimeTypes = [],
        int $maxSizeKb = 5120
    ): object {
        self::validate($file, $allowedMimeTypes, $maxSizeKb);

        $disk = self::disk();
        $path = $file->store($folder, $disk);

        if ($path === false) {
            throw new \RuntimeException('No se pudo almacenar el archivo.');
        }

        return (object) [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * Genera una URL firmada para acceder a un archivo privado.
     *
     * @param  string  $disk  Disco donde está almacenado el archivo.
     * @param  string  $path  Ruta del archivo dentro del disco.
     * @param  int|null  $expirationMinutes  Minutos hasta que expire la URL.
     * @param  array<string,mixed>  $options  Opciones adicionales para temporaryUrl.
     * @return string|null URL firmada o null si no se puede generar.
     */
    public static function temporaryUrl(
        string $disk,
        string $path,
        ?int $expirationMinutes = null,
        array $options = []
    ): ?string {
        if (in_array($disk, self::FORBIDDEN_DISKS, true)) {
            Log::warning("Intento de generar URL para disco inseguro: {$disk}");

            return null;
        }

        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $expiration = now()->addMinutes($expirationMinutes ?? self::DEFAULT_URL_EXPIRATION_MINUTES);

        try {
            return Storage::disk($disk)->temporaryUrl($path, $expiration, $options);
        } catch (\Exception $e) {
            Log::warning("Error generando URL temporal para {$disk}/{$path}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Elimina un archivo del disco donde está almacenado.
     *
     * @param  string  $disk  Disco del archivo.
     * @param  string  $path  Ruta del archivo.
     * @return bool True si se eliminó o no existía.
     */
    public static function delete(string $disk, string $path): bool
    {
        if (in_array($disk, self::FORBIDDEN_DISKS, true)) {
            Log::warning("Intento de eliminar archivo de disco inseguro: {$disk}");

            return false;
        }

        try {
            return Storage::disk($disk)->delete($path);
        } catch (\Exception $e) {
            Log::warning("Error eliminando {$disk}/{$path}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Valida un archivo antes de subirlo.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(
        UploadedFile $file,
        array $allowedMimeTypes = [],
        int $maxSizeKb = 5120
    ): void {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('El archivo no es válido.');
        }

        $maxSizeBytes = $maxSizeKb * 1024;
        if ($file->getSize() > $maxSizeBytes) {
            throw new InvalidArgumentException("El archivo excede el tamaño máximo permitido de {$maxSizeKb} KB.");
        }

        if ($allowedMimeTypes !== [] && ! in_array($file->getMimeType(), $allowedMimeTypes, true)) {
            throw new InvalidArgumentException('El tipo de archivo no está permitido.');
        }
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEmpresaDocumentoRequest;
use App\Models\Empresa;
use App\Services\SecureFileStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class EmpresaDocumentosController extends Controller
{
    /**
     * Listar documentos de la empresa con URLs firmadas.
     */
    public function index(Request $request)
    {
        $empresa = Empresa::find($request->user()->empresa_id);

        if (! $empresa) {
            return response()->json(['message' => 'Empresa no encontrada'], 404);
        }

        $documentos = $empresa->documentos ?? [];
        $documentos = array_map(function ($doc) {
            $doc['url'] = SecureFileStorage::temporaryUrl(
                $doc['disk'] ?? SecureFileStorage::disk(),
                $doc['path'] ?? null
            );

            return $doc;
        }, $documentos);

        return response()->json([
            'documentos' => $documentos,
        ]);
    }

    /**
     * Subir un nuevo documento.
     * Solo para administradores.
     */
    public function upload(StoreEmpresaDocumentoRequest $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $empresa = Empresa::find($u->empresa_id);
        if (! $empresa) {
            return response()->json(['message' => 'Empresa no encontrada'], 404);
        }

        try {
            $file = $request->file('file');
            $empresaId = $empresa->id;

            $stored = SecureFileStorage::upload(
                $file,
                "kore/{$empresaId}/documentos",
                ['application/pdf', 'image/jpeg', 'image/png'],
                10240
            );

            $nuevoDoc = [
                'nombre' => pathinfo($stored->original_name, PATHINFO_FILENAME),
                'disk' => $stored->disk,
                'path' => $stored->path,
                'size' => $stored->size,
                'uploaded_at' => now()->toISOString(),
            ];

            $documentos = $empresa->documentos ?? [];
            $documentos[] = $nuevoDoc;

            $empresa->documentos = $documentos;
            $empresa->save();

            $nuevoDoc['url'] = SecureFileStorage::temporaryUrl($stored->disk, $stored->path);

            return response()->json([
                'message' => 'Documento subido correctamente',
                'item' => $nuevoDoc,
                'all' => $documentos,
            ]);

        } catch (\Exception $e) {
            Log::error("Error al subir documento de empresa {$empresa->id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'Error al subir el archivo. Inténtalo de nuevo más tarde.',
            ], 500);
        }
    }

    /**
     * Eliminar un documento por su índice en el array.
     * Solo para administradores.
     */
    public function destroy(Request $request, int $index)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $empresa = Empresa::find($u->empresa_id);
        if (! $empresa) {
            return response()->json(['message' => 'Empresa no encontrada'], 404);
        }

        $documentos = $empresa->documentos ?? [];

        if (! isset($documentos[$index])) {
            return response()->json(['message' => 'Documento no encontrado'], 404);
        }

        try {
            $doc = $documentos[$index];

            // Eliminar del disco privado
            if (isset($doc['path']) && isset($doc['disk'])) {
                SecureFileStorage::delete($doc['disk'], $doc['path']);
            } elseif (isset($doc['path'])) {
                // Fallback para registros antiguos que no tienen 'disk'
                SecureFileStorage::delete(SecureFileStorage::disk(), $doc['path']);
            }

            // Eliminar del array
            array_splice($documentos, $index, 1);

            $empresa->documentos = $documentos;
            $empresa->save();

            return response()->json([
                'message' => 'Documento eliminado correctamente',
                'all' => $documentos,
            ]);

        } catch (\Exception $e) {
            Log::error("Error al eliminar documento de empresa {$empresa->id}: ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'Error al eliminar el archivo. Inténtalo de nuevo más tarde.',
            ], 500);
        }
    }
}

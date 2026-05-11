<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use App\Http\Requests\Api\V1\StoreEmpresaDocumentoRequest;

class EmpresaDocumentosController extends Controller
{
    /**
     * Listar documentos de la empresa.
     */
    public function index(Request $request)
    {
        $empresa = Empresa::find($request->user()->empresa_id);
        
        if (!$empresa) {
            return response()->json(['message' => 'Empresa no encontrada'], 404);
        }

        return response()->json([
            'documentos' => $empresa->documentos ?? [],
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
        if (!$empresa) {
            return response()->json(['message' => 'Empresa no encontrada'], 404);
        }

        try {
            $file = $request->file('file');
            $empresaId = $empresa->id;
            
            // Path: kore/{empresa_id}/documentos/{filename}
            $path = $file->store("kore/{$empresaId}/documentos", 's3');
            $url = Storage::disk('s3')->url($path);

            $nuevoDoc = [
                'nombre'      => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'url'         => $url,
                'path'        => $path,
                'size'        => $file->getSize(),
                'uploaded_at' => now()->toISOString(),
            ];

            $documentos = $empresa->documentos ?? [];
            $documentos[] = $nuevoDoc;
            
            $empresa->documentos = $documentos;
            $empresa->save();

            return response()->json([
                'message' => 'Documento subido correctamente',
                'item'    => $nuevoDoc,
                'all'     => $documentos
            ]);

        } catch (\Exception $e) {
            Log::error("Error al subir documento de empresa {$empresa->id}: " . $e->getMessage(), ['exception' => $e]);
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
        if (!$empresa) {
            return response()->json(['message' => 'Empresa no encontrada'], 404);
        }

        $documentos = $empresa->documentos ?? [];

        if (!isset($documentos[$index])) {
            return response()->json(['message' => 'Documento no encontrado'], 404);
        }

        try {
            $doc = $documentos[$index];
            
            // Eliminar de S3
            if (isset($doc['path']) && Storage::disk('s3')->exists($doc['path'])) {
                Storage::disk('s3')->delete($doc['path']);
            }

            // Eliminar del array
            array_splice($documentos, $index, 1);
            
            $empresa->documentos = $documentos;
            $empresa->save();

            return response()->json([
                'message' => 'Documento eliminado correctamente',
                'all'     => $documentos
            ]);

        } catch (\Exception $e) {
            Log::error("Error al eliminar documento de empresa {$empresa->id}: " . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'message' => 'Error al eliminar el archivo. Inténtalo de nuevo más tarde.',
            ], 500);
        }
    }
}

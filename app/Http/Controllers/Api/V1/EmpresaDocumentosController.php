<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

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
    public function upload(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'], // 10MB max
        ]);

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
            Log::error("Error al subir documento de empresa {$empresa->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Error al subir el archivo',
                'detail'  => $e->getMessage()
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
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

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
            Log::error("Error al eliminar documento de empresa {$empresa->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Error al eliminar el archivo',
                'detail'  => $e->getMessage()
            ], 500);
        }
    }
}

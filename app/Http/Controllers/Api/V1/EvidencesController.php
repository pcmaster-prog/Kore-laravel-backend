<?php

// EvidencesController: manejo de evidencias (archivos) subidos por empleados, adjuntos a tareas o asignaciones

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadEvidenceRequest;
use App\Http\Resources\EvidenceResource;
use App\Models\Empleado;
use App\Models\Evidence;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Services\ActivityLogger;
use App\Services\SecureFileStorage;
use Illuminate\Http\Request;

class EvidencesController extends Controller
{
    // POST /evidencias/upload  (multipart)
    public function upload(UploadEvidenceRequest $request)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validated();

        $disk = SecureFileStorage::disk();
        $file = $request->file('file');

        $empleadoId = null;
        if ($u->role === 'empleado') {
            $emp = Empleado::where('empresa_id', $empresaId)->where('user_id', $u->id)->first();
            $empleadoId = $emp?->id;
        }

        $folder = "kore/{$empresaId}/evidences/".now()->format('Y/m/d');
        $path = $file->store($folder, $disk);

        $mime = $file->getMimeType();
        $evidenceType = $this->detectEvidenceType($mime);

        $e = Evidence::create([
            'empresa_id' => $empresaId,
            'uploaded_by' => $u->id,
            'empleado_id' => $empleadoId,
            'task_id' => null,
            'task_assignee_id' => null,
            'evidence_type' => $evidenceType,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
            'meta' => $this->parseMeta($data['meta'] ?? null),
        ]);

        return (new EvidenceResource($e))
            ->additional(['url' => $this->fileUrl($e)])
            ->response()
            ->setStatusCode(201);
    }

    // POST /mis-tareas/asignacion/{assignmentId}/evidencia
    public function attachToMyAssignment(Request $request, string $assignmentId)
    {
        $u = $request->user();
        if ($u->role !== 'empleado') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $empresaId = $u->empresa_id;

        $emp = Empleado::where('empresa_id', $empresaId)->where('user_id', $u->id)->first();
        if (! $emp) {
            return response()->json(['message' => 'Empleado no vinculado'], 404);
        }

        $data = $request->validate([
            'evidence_id' => ['required', 'uuid'],
        ]);

        $a = TaskAssignee::where('empresa_id', $empresaId)
            ->where('id', $assignmentId)
            ->where('empleado_id', $emp->id)
            ->first();
        if (! $a) {
            return response()->json(['message' => 'Asignación no encontrada'], 404);
        }

        $e = Evidence::where('empresa_id', $empresaId)
            ->where('id', $data['evidence_id'])
            ->where('uploaded_by', $u->id)
            ->first();
        if (! $e) {
            return response()->json(['message' => 'Evidencia no encontrada'], 404);
        }

        $task = Task::where('empresa_id', $empresaId)->where('id', $a->task_id)->first();
        if (! $task) {
            return response()->json(['message' => 'Tarea no encontrada'], 404);
        }

        $e->task_assignee_id = $a->id;
        $e->task_id = $task->id;
        $e->empleado_id = $emp->id;
        $e->save();

        // 🔔 PUNTO 4.4: Logging al adjuntar evidencia a una tarea
        ActivityLogger::log(
            $empresaId,
            $u->id,
            $emp->id,
            'evidence.uploaded',
            'task',
            $task->id,
            [
                'evidence_id' => $e->id,
                'file_name' => $e->original_name ?? null,
                'task_title' => $task->title ?? null,    // <-- AGREGADO: título de la tarea para el feed de actividad
            ],
            $request
        );

        return response()->json([
            'message' => 'Evidencia ligada',
            'item' => new EvidenceResource($e),
            'url' => $this->fileUrl($e),
        ]);
    }

    // GET /evidencias/{id}
    public function show(Request $request, string $id)
    {
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $e = Evidence::where('empresa_id', $empresaId)->where('id', $id)->first();
        if (! $e) {
            return response()->json(['message' => 'No encontrada'], 404);
        }

        if ($u->role === 'empleado' && $e->uploaded_by !== $u->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json([
            'item' => new EvidenceResource($e),
            'url' => $this->fileUrl($e),
        ]);
    }

    /**
     * Genera una URL temporal firmada para la evidencia.
     */
    private function fileUrl(Evidence $e): ?string
    {
        if (! $e->path) {
            return null;
        }

        return SecureFileStorage::temporaryUrl(
            $e->disk,
            $e->path,
            30,
            ['ResponseContentDisposition' => 'inline']
        );
    }

    private function present(Evidence $e): array
    {
        return [
            'id' => $e->id,
            'task_id' => $e->task_id,
            'task_assignee_id' => $e->task_assignee_id,
            'uploaded_by' => $e->uploaded_by,
            'empleado_id' => $e->empleado_id,
            'disk' => $e->disk,
            'path' => $e->path,
            'original_name' => $e->original_name,
            'mime' => $e->mime,
            'size' => $e->size,
            'created_at' => $e->created_at?->toISOString(),
        ];
    }

    private function detectEvidenceType(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'photo';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'voice_note';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return 'file';
    }

    private function parseMeta($meta)
    {
        if (! $meta) {
            return null;
        }
        if (is_array($meta)) {
            return $meta;
        }
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => $meta];
        }

        return null;
    }
}

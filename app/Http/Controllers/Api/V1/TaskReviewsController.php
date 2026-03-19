<?php
//TaskReviewsController: revisión de tareas por parte de supervisores y admins, cola de revisión, aprobación/rechazo, visualización de evidencias
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\Evidence;

class TaskReviewsController extends Controller
{
    public function reviewQueue(Request $request)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $query = TaskAssignee::where('empresa_id', $u->empresa_id)
            ->with(['task', 'empleado'])
            ->orderByDesc('updated_at');

        if ($request->filled('review_status')) {
            $query->where('review_status', $request->string('review_status'));
        } else {
            $query->where('review_status', 'pending');
        }

        if ($request->filled('date')) {
            $date = $request->string('date');
            $query->whereHas('task', fn($t) =>
                $t->whereRaw("meta->>'catalog_date' = ?", [$date])
            );
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->whereHas('task', fn($t) =>
                $t->where('title', 'ilike', "%{$s}%")
            );
        }

        return response()->json($query->paginate(20));
    }

    public function reviewAssignment(Request $request, string $assignmentId)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $a = TaskAssignee::where('empresa_id', $u->empresa_id)
            ->where('id', $assignmentId)
            ->with(['task', 'empleado'])
            ->first();

        if (!$a) {
            return response()->json(['message' => 'Asignación no encontrada'], 404);
        }

        if ($a->review_status !== 'pending') {
            return response()->json(['message' => 'Solo puedes revisar asignaciones en estado pending'], 422);
        }

        $hasEvidence = Evidence::where('empresa_id', $u->empresa_id)
            ->where('task_assignee_id', $a->id)
            ->exists();

        if (!$hasEvidence) {
            return response()->json(['message' => 'No puedes aprobar sin evidencia ligada.'], 422);
        }

        $a->reviewed_at = now();
        $a->reviewed_by = $u->id;

        if ($data['action'] === 'approve') {
            $a->review_status = 'approved';
            $a->review_note = null;
        } else {
            $a->review_status = 'rejected';
            $a->review_note = $data['note'] ?? 'Rechazada';
        }

        $a->save();

        app(TasksController::class)->recomputeTaskStatus($u->empresa_id, $a->task_id, $u, $request);

        return response()->json([
            'message' => $data['action'] === 'approve' ? 'Aprobada ✅' : 'Rechazada ❌',
            'assignment_id' => $a->id,
            'review_status' => $a->review_status
        ]);
    }

    public function taskEvidences(Request $request, string $id)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $task = Task::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$task) {
            return response()->json(['message' => 'Tarea no encontrada'], 404);
        }

        $evidences = Evidence::where('empresa_id', $u->empresa_id)
            ->where('task_id', $task->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'task_id' => $e->task_id,
                'task_assignee_id' => $e->task_assignee_id,
                'empleado_id' => $e->empleado_id,
                'original_name' => $e->original_name,
                'mime' => $e->mime,
                'size' => $e->size,
                'created_at' => $e->created_at?->toISOString(),
                'url' => $this->evidenceFileUrl($e),
            ]);

        return response()->json(['data' => $evidences]);
    }

    private function evidenceFileUrl(Evidence $evidence): ?string
    {
        if (!$evidence->path) return null;

        if ($evidence->disk === 's3') {
            try {
                return \Storage::temporaryUrl(
                    $evidence->path,
                    now()->addMinutes(30),
                    ['ResponseContentDisposition' => 'inline']
                );
            } catch (\Exception $ex) {
                \Log::warning("Error URL temporal evidence {$evidence->id}: " . $ex->getMessage());
                return null;
            }
        }

        try {
            return \Storage::url($evidence->path);
        } catch (\Exception $ex) {
            \Log::warning("Error URL evidence {$evidence->id}: " . $ex->getMessage());
            return null;
        }
    }
}
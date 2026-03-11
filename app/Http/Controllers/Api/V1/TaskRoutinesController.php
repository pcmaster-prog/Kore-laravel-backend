<?php
//TaskRoutinesController: CRUD de rutinas, asignación masiva, manejo de items (templates)
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

use App\Models\TaskRoutine;
use App\Models\TaskRoutineItem;
use App\Models\TaskTemplate;

class TaskRoutinesController extends Controller
{
    private function requireManager($u)
    {
        if (!in_array($u->role, ['admin','supervisor'])) {
            abort(response()->json(['message'=>'No autorizado'], 403));
        }
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $this->requireManager($u);

        $q = TaskRoutine::where('empresa_id',$u->empresa_id);

        if ($request->filled('active')) {
            $q->where('is_active', filter_var($request->string('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($q->orderBy('name')->paginate(20));
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $this->requireManager($u);

        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'description' => ['nullable','string'],
            'recurrence' => ['required', Rule::in(['daily','weekly'])],
            'weekdays' => ['nullable','array'],
            'weekdays.*' => ['integer','min:0','max:6'],
            'start_date' => ['nullable','date'],
            'end_date' => ['nullable','date'],
            'is_active' => ['nullable','boolean'],
        ]);

        if ($data['recurrence'] === 'weekly' && empty($data['weekdays'])) {
            return response()->json(['message'=>'weekdays requerido para weekly'], 422);
        }

        $r = TaskRoutine::create([
            'empresa_id'=>$u->empresa_id,
            'created_by'=>$u->id,
            'name'=>$data['name'],
            'description'=>$data['description'] ?? null,
            'recurrence'=>$data['recurrence'],
            'weekdays'=>$data['weekdays'] ?? null,
            'start_date'=>$data['start_date'] ?? null,
            'end_date'=>$data['end_date'] ?? null,
            'is_active'=>$data['is_active'] ?? true,
        ]);

        return response()->json(['item'=>$r], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        $this->requireManager($u);

        $r = TaskRoutine::where('empresa_id',$u->empresa_id)->where('id',$id)->first();
        if (!$r) return response()->json(['message'=>'No encontrado'], 404);

        $items = TaskRoutineItem::where('empresa_id',$u->empresa_id)
            ->where('routine_id',$r->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['item'=>$r, 'items'=>$items]);
    }

    public function update(Request $request, string $id)
    {
        $u = $request->user();
        $this->requireManager($u);

        $r = TaskRoutine::where('empresa_id',$u->empresa_id)->where('id',$id)->first();
        if (!$r) return response()->json(['message'=>'No encontrado'], 404);

        $data = $request->validate([
            'name' => ['sometimes','string','max:120'],
            'description' => ['sometimes','nullable','string'],
            'recurrence' => ['sometimes', Rule::in(['daily','weekly'])],
            'weekdays' => ['sometimes','nullable','array'],
            'weekdays.*' => ['integer','min:0','max:6'],
            'start_date' => ['sometimes','nullable','date'],
            'end_date' => ['sometimes','nullable','date'],
            'is_active' => ['sometimes','boolean'],
        ]);

        $r->fill($data);
        $r->save();

        return response()->json(['item'=>$r]);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') return response()->json(['message'=>'Solo admin'], 403);

        $r = TaskRoutine::where('empresa_id',$u->empresa_id)->where('id',$id)->first();
        if (!$r) return response()->json(['message'=>'No encontrado'], 404);

        $r->delete();
        return response()->json(['message'=>'Eliminado']);
    }

    // POST /task-routines/{id}/items
    public function addItems(Request $request, string $id)
    {
        $u = $request->user();
        $this->requireManager($u);

        $r = TaskRoutine::where('empresa_id',$u->empresa_id)->where('id',$id)->first();
        if (!$r) return response()->json(['message'=>'Rutina no encontrada'], 404);

        $data = $request->validate([
            'template_ids' => ['required','array','min:1'],
            'template_ids.*' => ['uuid'],
        ]);

        // validar templates de la empresa
        $valid = TaskTemplate::where('empresa_id',$u->empresa_id)
            ->whereIn('id', $data['template_ids'])
            ->pluck('id')->all();

        if (count($valid) !== count($data['template_ids'])) {
            return response()->json(['message'=>'Uno o más templates no pertenecen a la empresa'], 422);
        }

        $maxSort = (int) TaskRoutineItem::where('empresa_id',$u->empresa_id)
            ->where('routine_id',$r->id)
            ->max('sort_order');

        foreach ($valid as $tid) {
            $maxSort++;
            TaskRoutineItem::firstOrCreate(
                ['empresa_id'=>$u->empresa_id,'routine_id'=>$r->id,'template_id'=>$tid],
                ['sort_order'=>$maxSort,'is_active'=>true]
            );
        }

        return response()->json(['message'=>'Items agregados']);
    }

    // DELETE /task-routines/{id}/items/{itemId}
    public function removeItem(Request $request, string $id, string $itemId)
    {
        $u = $request->user();
        $this->requireManager($u);

        $item = TaskRoutineItem::where('empresa_id',$u->empresa_id)
            ->where('routine_id',$id)
            ->where('id',$itemId)
            ->first();

        if (!$item) return response()->json(['message'=>'Item no encontrado'], 404);

        $item->delete();
        return response()->json(['message'=>'Item eliminado']);
    }
    public function assignRoutine(Request $request, string $id)
    {
    $u = $request->user();
    $this->requireManager($u);

    $empresaId = $u->empresa_id;

    $data = $request->validate([
        'date' => ['required','date'],
        'empleado_ids' => ['required','array','min:1'],
        'empleado_ids.*' => ['uuid'],
        'due_at' => ['nullable','date'],
        'allow_duplicate' => ['nullable','boolean'],
    ]);

    $routine = TaskRoutine::where('empresa_id',$empresaId)
        ->where('id',$id)
        ->where('is_active',true)
        ->first();

    if (!$routine) {
        return response()->json(['message'=>'Rutina no encontrada'], 404);
    }

    // validar que la rutina aplique a la fecha
    $dow = \Carbon\Carbon::parse($data['date'])->dayOfWeek;

    if ($routine->recurrence === 'weekly') {
        $weekdays = is_array($routine->weekdays) ? $routine->weekdays : [];
        if (!in_array($dow, $weekdays, true)) {
            return response()->json([
                'message'=>'La rutina no aplica a esta fecha'
            ], 422);
        }
    }

    // obtener templates activos de la rutina
    $items = TaskRoutineItem::where('empresa_id',$empresaId)
        ->where('routine_id',$routine->id)
        ->where('is_active',true)
        ->orderBy('sort_order')
        ->get();

    if ($items->isEmpty()) {
        return response()->json(['message'=>'La rutina no tiene tareas'], 422);
    }

    // Reusar el bulk assign
    $bulkRequest = new Request([
        'date' => $data['date'],
        'template_ids' => $items->pluck('template_id')->all(),
        'empleado_ids' => $data['empleado_ids'],
        'due_at' => $data['due_at'] ?? null,
        'allow_duplicate' => $data['allow_duplicate'] ?? false,
    ]);

    $bulkRequest->setUserResolver(fn() => $u);

    return app(\App\Http\Controllers\Api\V1\TaskCatalogController::class)
        ->createBulkFromTemplates($bulkRequest);
        }

}

<?php
//EmployeesController: manejo de empleados, vinculación con usuarios, configuración de calendario individual (horas diarias, día de descanso semanal, overrides)
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

use App\Models\Empleado;

class EmployeesController extends Controller
{
    /**
     * Listado (admin y supervisor).
     * Soporta filtros básicos: status y búsqueda.
     */
    public function index(Request $request)
    {
        $u = $request->user();

        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $empresaId = $u->empresa_id;

        $q = Empleado::query()->where('empresa_id', $empresaId);

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where(function ($w) use ($s) {
                $w->where('full_name', 'ilike', "%{$s}%")
                  ->orWhere('employee_code', 'ilike', "%{$s}%")
                  ->orWhere('position_title', 'ilike', "%{$s}%");
            });
        }

        $items = $q->orderBy('full_name')
                  ->paginate(20);

        return response()->json($items);
    }

    /**
     * Crear empleado (solo admin).
     */
    public function store(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'full_name' => ['required','string','max:160'],
            'employee_code' => ['nullable','string','max:50'],
            'position_title' => ['nullable','string','max:120'],
            'status' => ['nullable', Rule::in(['active','inactive'])],
            'hired_at' => ['nullable','date'],
        ]);

        $emp = Empleado::create([
            'empresa_id' => $empresaId,
            'user_id' => null,
            'full_name' => $data['full_name'],
            'employee_code' => $data['employee_code'] ?? null,
            'position_title' => $data['position_title'] ?? null,
            'status' => $data['status'] ?? 'active',
            'hired_at' => $data['hired_at'] ?? null,
        ]);

        return response()->json(['item'=>$this->present($emp)], 201);
    }

    /**
     * Ver empleado (admin y supervisor, dentro de su empresa).
     */
    public function show(Request $request, string $id)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $emp = Empleado::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$emp) return response()->json(['message'=>'No encontrado'], 404);

        return response()->json(['item'=>$this->present($emp)]);
    }

    /**
     * Actualizar empleado (solo admin).
     */
    public function update(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $emp = Empleado::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$emp) return response()->json(['message'=>'No encontrado'], 404);

        $data = $request->validate([
            'full_name' => ['sometimes','string','max:160'],
            'employee_code' => ['sometimes','nullable','string','max:50'],
            'position_title' => ['sometimes','nullable','string','max:120'],
            'status' => ['sometimes', Rule::in(['active','inactive'])],
            'hired_at' => ['sometimes','nullable','date'],
        ]);

        $emp->fill($data);
        $emp->save();

        return response()->json(['item'=>$this->present($emp)]);
    }

    //vincular usuario-empleado
    public function linkUser(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $data = $request->validate([
            'user_id' => ['required','uuid'],
        ]);

        // 1) El empleado debe existir y ser de la misma empresa
        $emp = \App\Models\Empleado::where('empresa_id', $u->empresa_id)
            ->where('id', $id)
            ->first();
        if (!$emp) return response()->json(['message'=>'Empleado no encontrado'], 404);

        // 2) El usuario debe existir y pertenecer a la misma empresa
        $targetUser = \App\Models\User::where('empresa_id', $u->empresa_id)
            ->where('id', $data['user_id'])
            ->first();
        if (!$targetUser) {
            return response()->json(['message'=>'Usuario no encontrado en esta empresa'], 404);
        }

        // 3) Evitar que un user tenga 2 empleados
        $already = \App\Models\Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $targetUser->id)
            ->first();
        if ($already && $already->id !== $emp->id) {
            return response()->json([
                'message' => 'Este usuario ya está vinculado a otro empleado',
                'linked_employee_id' => $already->id
            ], 409);
        }

        // 4) Vincular
        $emp->user_id = $targetUser->id;
        $emp->save();

        return response()->json([
            'message' => 'Vinculación OK',
            'item' => $this->present($emp),
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $targetUser->role,
            ]
        ]);
    }

    /**
     * Perfil del empleado autenticado (rol empleado).
     * Requiere que exista un registro empleado asociado al user_id.
     */
    public function me(Request $request)
    {
        $u = $request->user();

        if ($u->role !== 'empleado') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        if (!$emp) {
            return response()->json([
                'message'=>'Empleado no vinculado aún',
                'hint'=>'El admin debe vincular este usuario a un registro de empleado'
            ], 404);
        }

        return response()->json(['item'=>$this->present($emp)]);
    }

    public function updateCalendar(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $emp = \App\Models\Empleado::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$emp) return response()->json(['message'=>'Empleado no encontrado'], 404);

        $data = $request->validate([
            'daily_hours' => ['sometimes','numeric','min:0','max:24'],
            'rest_weekday' => ['sometimes','nullable','integer','min:0','max:6'],
        ]);

        $emp->fill($data);
        $emp->save();

        return response()->json([
            'message'=>'Empleado calendar updated',
            'item'=>$this->present($emp),
        ]);
    }

    public function upsertCalendarOverride(Request $request, string $id)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin','supervisor'])) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $emp = \App\Models\Empleado::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$emp) return response()->json(['message'=>'Empleado no encontrado'], 404);

        $data = $request->validate([
            'date' => ['required','date'],
            'type' => ['required', \Illuminate\Validation\Rule::in(['workday','rest'])],
            'is_paid' => ['nullable','boolean'],
            'paid_minutes' => ['nullable','integer','min:0','max:1440'],
            'note' => ['nullable','string','max:2000'],
        ]);

        // Si es workday, is_paid/paid_minutes no aplican
        if ($data['type'] === 'workday') {
            $data['is_paid'] = false;
            $data['paid_minutes'] = null;
        } else {
            $data['is_paid'] = (bool)($data['is_paid'] ?? false);
            // paid_minutes puede ser null => usar daily_hours*60 en cálculos
        }

        $ov = \App\Models\EmployeeCalendarOverride::updateOrCreate(
            [
                'empresa_id' => $u->empresa_id,
                'empleado_id' => $emp->id,
                'date' => $data['date'],
            ],
            [
                'type' => $data['type'],
                'is_paid' => $data['is_paid'],
                'paid_minutes' => $data['paid_minutes'] ?? null,
                'note' => $data['note'] ?? null,
            ]
        );

        return response()->json([
            'message'=>'Override saved',
            'override' => [
                'id'=>$ov->id,
                'date'=>$ov->date?->toDateString(),
                'type'=>$ov->type,
                'is_paid'=>$ov->is_paid,
                'paid_minutes'=>$ov->paid_minutes,
                'note'=>$ov->note,
            ]
        ]);
    }

    private function present(Empleado $e): array
    {
        return [
            'id' => $e->id,
            'full_name' => $e->full_name,
            'employee_code' => $e->employee_code,
            'position_title' => $e->position_title,
            'status' => $e->status,
            'hired_at' => $e->hired_at?->toDateString(),
            'user_id' => $e->user_id,
            'created_at' => $e->created_at?->toISOString(),
            'updated_at' => $e->updated_at?->toISOString(),
        ];
    }
}
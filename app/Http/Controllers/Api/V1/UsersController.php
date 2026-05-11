<?php
// app/Http/Controllers/Api/V1/UsersController.php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Mail\BienvenidaEmpleado;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendWelcomeEmail;
use Illuminate\Support\Facades\Gate;

class UsersController extends Controller
{
    /**
     * Listar usuarios de la empresa (admin).
     */
    public function index(Request $request)
    {
        Gate::authorize('manage-users');

        $u = $request->user();

        $q = User::where('empresa_id', $u->empresa_id);

        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where(function ($w) use ($s) {
                $w->where('name', 'ilike', "%{$s}%")
                  ->orWhere('email', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('role')) {
            $q->where('role', $request->string('role'));
        }

        $users = $q->with('empleado')->orderBy('name')->paginate(20);

        return UserResource::collection($users);
    }

    /**
     * Ver un usuario específico (admin).
     */
    public function show(Request $request, string $id)
    {
        Gate::authorize('manage-users');

        $u = $request->user();

        $user = User::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        return new UserResource($user->load('empleado'));
    }

    /**
     * Crear usuario + empleado en una sola llamada (admin).
     * Crea el User con credenciales y el registro Empleado vinculado.
     */
    public function store(StoreUserRequest $request)
    {
        Gate::authorize('manage-users');

        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validated();

        DB::beginTransaction();
        try {
            // 1) Generar contraseña si no viene en el request
            $passwordTemporal = $data['password'] ?? $this->generarPasswordTemporal();

            // 2) Crear User
            $newUser = User::create([
                'empresa_id' => $empresaId,
                'name'       => $data['name'],
                'email'      => $data['email'],
                'password'   => Hash::make($passwordTemporal),
                'role'       => $data['role'],
                'is_active'  => true,
            ]);

            // 2) Crear registro Empleado y vincularlo
            $emp = Empleado::create([
                'empresa_id'     => $empresaId,
                'user_id'        => $newUser->id,
                'full_name'      => $data['name'],
                'employee_code'  => $data['employee_code'] ?? null,
                'position_title' => $data['position_title'] ?? null,
                'status'         => 'active',
                'hired_at'       => $data['hired_at'] ?? null,
                'payment_type'   => $data['payment_type'] ?? 'hourly',
                'hourly_rate'    => $data['hourly_rate'] ?? 0,
                'daily_rate'     => $data['daily_rate'] ?? 0,
                'rfc'            => $data['rfc'] ?? null,
                'nss'            => $data['nss'] ?? null,
                'curp'           => $data['curp'] ?? null,
            ]);

            DB::commit();

            SendWelcomeEmail::dispatch($newUser->id, $passwordTemporal);
            $emailSent = true;

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al crear usuario: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Error al crear usuario. Inténtalo de nuevo más tarde.'], 500);
        }

        return (new UserResource($newUser->load('empleado')))
            ->additional(['email_sent' => $emailSent])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Actualizar usuario (admin).
     * Actualiza User y su Empleado vinculado.
     */
    public function update(UpdateUserRequest $request, string $id)
    {
        Gate::authorize('manage-users');

        $u = $request->user();

        $target = User::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$target) return response()->json(['message' => 'Usuario no encontrado'], 404);

        $data = $request->validated();

        DB::beginTransaction();
        try {
            // Actualizar User
            if (isset($data['name']))      $target->name = $data['name'];
            if (isset($data['email']))     $target->email = $data['email'];
            if (isset($data['role']))      $target->role = $data['role'];
            if (isset($data['is_active'])) $target->is_active = $data['is_active'];
            if (!empty($data['password'])) $target->password = Hash::make($data['password']);
            $target->save();

            // Actualizar Empleado vinculado si existe
            $emp = Empleado::where('user_id', $target->id)->first();
            if ($emp) {
                if (isset($data['name']))           $emp->full_name = $data['name'];
                if (isset($data['position_title'])) $emp->position_title = $data['position_title'];
                if (isset($data['employee_code']))  $emp->employee_code = $data['employee_code'];
                if (isset($data['hired_at']))       $emp->hired_at = $data['hired_at'];
                if (isset($data['payment_type']))  $emp->payment_type = $data['payment_type'];
                if (isset($data['hourly_rate']))   $emp->hourly_rate  = $data['hourly_rate'];
                if (isset($data['daily_rate']))    $emp->daily_rate   = $data['daily_rate'];
                if (isset($data['rfc']))           $emp->rfc = $data['rfc'];
                if (isset($data['nss']))           $emp->nss = $data['nss'];
                if (isset($data['curp']))          $emp->curp = $data['curp'];
                if (isset($data['is_active']))      $emp->status = $data['is_active'] ? 'active' : 'inactive';
                $emp->save();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al actualizar usuario: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Error al actualizar. Inténtalo de nuevo más tarde.'], 500);
        }

        return new UserResource($target->load('empleado'));
    }

    /**
     * Toggle activo/inactivo (admin).
     */
    public function toggleStatus(Request $request, string $id)
    {
        Gate::authorize('manage-users');

        $u = $request->user();

        // Evitar que el admin se desactive a sí mismo
        if ($u->id === $id) {
            return response()->json(['message' => 'No puedes desactivarte a ti mismo'], 409);
        }

        $target = User::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$target) return response()->json(['message' => 'Usuario no encontrado'], 404);

        $target->is_active = !$target->is_active;
        $target->save();

        // Sincronizar status del empleado
        $emp = Empleado::where('user_id', $target->id)->first();
        if ($emp) {
            $emp->status = $target->is_active ? 'active' : 'inactive';
            $emp->save();
        }

        return (new UserResource($target->load('empleado')))
            ->additional(['message' => $target->is_active ? 'Usuario activado' : 'Usuario desactivado']);
    }

    /**
     * Eliminar usuario y empleado vinculado (admin).
     */
    public function destroy(Request $request, string $id)
    {
        Gate::authorize('manage-users');

        $u = $request->user();

        if ($u->id === $id) {
            return response()->json(['message' => 'No puedes eliminarte a ti mismo'], 409);
        }

        $targetUser = User::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$targetUser) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $emp = Empleado::where('user_id', $targetUser->id)->first();

        DB::beginTransaction();
        try {
            if ($emp) {
                // Verificar si tiene nóminas aprobadas
                $hasApprovedPayrolls = \App\Models\PayrollEntry::where('empleado_id', $emp->id)
                    ->whereHas('period', function($q) {
                        $q->where('status', 'approved');
                    })->exists();

                if ($hasApprovedPayrolls) {
                    return response()->json([
                        'message' => 'No se puede eliminar el empleado porque tiene periodos de nómina aprobados. Por favor, desactívelo en su lugar.'
                    ], 409);
                }

                // Borrar nóminas en draft (no aprobadas)
                \App\Models\PayrollEntry::where('empleado_id', $emp->id)->delete();

                // Borrar archivos de evidencias del storage y los registros en BD
                $evidences = \Illuminate\Support\Facades\DB::table('evidences')->where('empleado_id', $emp->id)->get();
                foreach ($evidences as $ev) {
                    if (!empty($ev->file_path)) {
                        $disk = config('filesystems.default', 's3');
                        \Illuminate\Support\Facades\Storage::disk($disk)->delete($ev->file_path);
                    }
                }
                \Illuminate\Support\Facades\DB::table('evidences')->where('empleado_id', $emp->id)->delete();

                // Borrar dependencias en cascada
                \Illuminate\Support\Facades\DB::table('task_assignees')->where('empleado_id', $emp->id)->delete();
                \Illuminate\Support\Facades\DB::table('attendance_days')->where('empleado_id', $emp->id)->delete();
                
                // Borrar el modelo de empleado
                $emp->delete();
            }

            $targetUser->delete();

            DB::commit();
            return response()->json(['message' => 'Empleado eliminado'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al eliminar empleado: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Error al eliminar empleado. Inténtalo de nuevo más tarde.'], 500);
        }
    }

    private function present(User $user, ?Empleado $emp = null): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'role'           => $user->role,
            'is_active'      => (bool) $user->is_active,
            'created_at'     => $user->created_at?->toISOString(),
            // Datos del empleado vinculado
            'employee_id'    => $emp?->id,
            'employee_code'  => $emp?->employee_code,
            'position_title' => $emp?->position_title,
            'hired_at'       => $emp?->hired_at?->toDateString(),
            'payment_type'   => $emp?->payment_type ?? 'hourly',
            'hourly_rate'    => $emp?->hourly_rate ?? 0,
            'daily_rate'     => $emp?->daily_rate ?? 0,
            'rfc'            => $emp?->rfc,
            'nss'            => $emp?->nss,
            'curp'           => $emp?->curp,
        ];
    }
    private function generarPasswordTemporal(): string
    {
        return Str::password(12, letters: true, numbers: true, symbols: true, spaces: false);
    }
}
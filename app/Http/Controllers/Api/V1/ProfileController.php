<?php

// app/Http/Controllers/Api/V1/ProfileController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Requests\Api\V1\UploadAvatarRequest;
use App\Models\Application;
use App\Models\AttendanceDay;
use App\Models\Empleado;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    // ...
    // GET /mi-perfil
    public function show(Request $request)
    {
        $u = $request->user();

        // Busca el empleado vinculado (puede no existir para admin/supervisor sin empleado)
        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        // Estado de asistencia hoy (solo si es empleado)
        $attendanceStatus = null;
        if ($emp) {
            $today = now()->toDateString();
            $day = AttendanceDay::where('empresa_id', $u->empresa_id)
                ->where('empleado_id', $emp->id)
                ->where('date', $today)
                ->first();

            if (! $day || ! $day->first_check_in_at) {
                $attendanceStatus = 'absent';
            } else {
                // Determina si fue tarde según first_check_in_at vs hora de entrada configurada
                // Por simplicidad: si hay datos, está presente
                $attendanceStatus = 'on_time';
            }
        }

        return response()->json([
            'data' => $this->presentProfile($u, $emp, $attendanceStatus),
            'meta' => $this->portalMeta($u),
        ]);
    }

    // PATCH /mi-perfil
    public function update(UpdateProfileRequest $request)
    {
        $u = $request->user();

        $data = $request->validated();

        // Actualiza el nombre en users
        if (isset($data['full_name'])) {
            $u->name = $data['full_name'];
            $u->save();
        }

        // Actualiza phone y address en users
        if (array_key_exists('phone', $data)) {
            $u->phone = $data['phone'];
        }
        if (array_key_exists('address', $data)) {
            $u->address = $data['address'];
        }
        $u->save();

        // Si tiene empleado vinculado, sincroniza el full_name y curp
        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        if ($emp) {
            if (isset($data['full_name'])) {
                $emp->full_name = $data['full_name'];
            }
            if (array_key_exists('curp', $data)) {
                $emp->curp = $data['curp'];
            }
            $emp->save();
        }

        return response()->json([
            'data' => $this->presentProfile($u->fresh(), $emp, null),
            'meta' => $this->portalMeta($u),
        ]);
    }

    // PUT /users/preferences
    public function updatePreferences(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'notifications_enabled' => ['sometimes', 'nullable', 'boolean'],
            'language' => ['sometimes', 'nullable', 'string', 'max:5'],
            'theme' => ['sometimes', 'nullable', 'string', 'max:10', 'in:system,light,dark'],
        ]);

        $u->fill($data);
        $u->save();

        return response()->json([
            'message' => 'Preferencias actualizadas correctamente.',
            'data' => [
                'notifications_enabled' => $u->notifications_enabled,
                'language' => $u->language,
                'theme' => $u->theme,
            ],
        ]);
    }

    // POST /mi-perfil/avatar
    public function uploadAvatar(UploadAvatarRequest $request)
    {
        $u = $request->user();

        $request->validated();

        // Borra el avatar anterior si existe
        if ($u->avatar_url) {
            // Extraer el path del URL de S3
            // Si el URL es temporal o tiene query params, necesitamos solo el path
            $parsedUrl = parse_url($u->avatar_url);
            $oldPath = ltrim($parsedUrl['path'], '/');

            if (Storage::disk('s3')->exists($oldPath)) {
                Storage::disk('s3')->delete($oldPath);
            }
        }

        $file = $request->file('avatar');
        $path = $file->store("kore/{$u->empresa_id}/avatars", 's3');
        $url = Storage::disk('s3')->temporaryUrl($path, now()->addYears(1));

        $u->avatar_url = $url;
        $u->save();

        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        return response()->json([
            'data' => $this->presentProfile($u->fresh(), $emp, null),
            'meta' => $this->portalMeta($u),
        ]);
    }

    // POST /mi-perfil/password
    public function changePassword(ChangePasswordRequest $request)
    {
        $u = $request->user();

        $data = $request->validated();

        // Verificar contraseña actual
        if (! Hash::check($data['current_password'], $u->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta.',
            ], 422);
        }

        $u->password = Hash::make($data['new_password']);
        $u->save();

        ActivityLogger::log(
            $u->empresa_id,
            $u->id,
            null,
            'password_changed',
            'user',
            $u->id,
            null,
            $request
        );

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    private function portalMeta($u): array
    {
        return [
            'has_portal_access' => $this->hasPortalAccess($u),
            'portal_url' => config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx'),
            'application_status' => $this->applicationStatus($u),
        ];
    }

    private function hasPortalAccess($u): bool
    {
        // Los aspirantes siempre acceden a su portal.
        // Los usuarios de empresa acceden al portal público de vacantes de su empresa.
        return $u->role === 'aspirante' || filled($u->empresa_id);
    }

    private function applicationStatus($u): ?string
    {
        if ($u->role !== 'aspirante') {
            return null;
        }

        $latest = Application::where('user_id', $u->id)->latest()->first();

        return $latest?->status;
    }

    private function presentProfile($u, $emp, ?string $attendanceStatus): array
    {
        return [
            'id' => $u->id,
            'full_name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone ?? null,
            'address' => $u->address ?? null,
            'avatar_url' => $u->avatar_url ?? null,
            'role' => $u->role,

            // Datos del empleado
            'employee_number' => $emp?->employee_code ?? null,
            'position_title' => $emp?->position_title ?? null,
            'department' => null, // campo futuro
            'hire_date' => $emp?->hired_at?->toDateString() ?? null,
            'curp' => $emp?->curp ?? null,
            // Nómina
            'pay_type' => $emp?->payment_type ?? null,
            'hourly_rate' => $emp?->payment_type === 'hourly' ? ($emp?->hourly_rate ?? null) : null,
            'daily_rate' => $emp?->payment_type === 'daily' ? ($emp?->daily_rate ?? null) : null,
            // Asistencia
            'attendance_status' => $attendanceStatus,
        ];
    }
}

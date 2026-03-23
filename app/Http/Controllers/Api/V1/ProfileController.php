<?php
// app/Http/Controllers/Api/V1/ProfileController.php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\AttendanceDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;


class ProfileController extends Controller
{
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

            if (!$day || !$day->first_check_in_at) {
                $attendanceStatus = 'absent';
            } else {
                // Determina si fue tarde según first_check_in_at vs hora de entrada configurada
                // Por simplicidad: si hay datos, está presente
                $attendanceStatus = 'on_time';
            }
        }

        return response()->json([
            'data' => $this->presentProfile($u, $emp, $attendanceStatus),
        ]);
    }

    // PATCH /mi-perfil
    public function update(Request $request)
    {
        $u = $request->user();

        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:150'],
            'phone'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'address'   => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

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

        // Si tiene empleado vinculado, sincroniza el full_name ahí también
        $emp = Empleado::where('empresa_id', $u->empresa_id)
            ->where('user_id', $u->id)
            ->first();

        if ($emp && isset($data['full_name'])) {
            $emp->full_name = $data['full_name'];
            $emp->save();
        }

        return response()->json([
            'data' => $this->presentProfile($u->fresh(), $emp?->fresh(), null),
        ]);
    }

    // POST /mi-perfil/avatar
    public function uploadAvatar(Request $request)
    {
        $u = $request->user();
        
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'] // max 2MB
        ]);

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
        $url  = Storage::disk('s3')->temporaryUrl($path, now()->addYears(1));

        $u->avatar_url = $url;
        $u->save();

        return response()->json(['avatar_url' => $url]);
    }


    private function presentProfile($u, $emp, ?string $attendanceStatus): array
    {
        return [
            'id'                => $u->id,
            'full_name'         => $u->name,
            'email'             => $u->email,
            'phone'             => $u->phone ?? null,
            'address'           => $u->address ?? null,
            'avatar_url'        => $u->avatar_url ?? null,
            'role'              => $u->role,

            // Datos del empleado
            'employee_number'   => $emp?->employee_code ?? null,
            'position_title'    => $emp?->position_title ?? null,
            'department'        => null, // campo futuro
            'hire_date'         => $emp?->hired_at?->toDateString() ?? null,
            // Nómina
            'pay_type'          => $emp?->payment_type ?? null,
            'hourly_rate'       => $emp?->payment_type === 'hourly' ? ($emp?->hourly_rate ?? null) : null,
            'daily_rate'        => $emp?->payment_type === 'daily'  ? ($emp?->daily_rate  ?? null) : null,
            // Asistencia
            'attendance_status' => $attendanceStatus,
        ];
    }
}
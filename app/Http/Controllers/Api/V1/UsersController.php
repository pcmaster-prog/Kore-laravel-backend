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

class UsersController extends Controller
{
    /**
     * Listar usuarios de la empresa (admin).
     */
    public function index(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

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

        $users = $q->orderBy('name')->paginate(20);

        // Adjuntar info del empleado vinculado si existe
        $users->getCollection()->transform(function ($user) {
            $emp = Empleado::where('user_id', $user->id)->first();
            return $this->present($user, $emp);
        });

        return response()->json($users);
    }

    /**
     * Ver un usuario específico (admin).
     */
    public function show(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $user = User::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $emp = Empleado::where('user_id', $user->id)->first();
        return response()->json(['item' => $this->present($user, $emp)]);
    }

    /**
     * Crear usuario + empleado en una sola llamada (admin).
     * Crea el User con credenciales y el registro Empleado vinculado.
     */
    public function store(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:160'],
            'email'          => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'password'       => ['nullable', 'string', 'min:6', 'max:100'],
            'role'           => ['required', Rule::in(['admin', 'supervisor', 'empleado'])],
            // Campos del empleado (opcionales)
            'employee_code'  => ['nullable', 'string', 'max:50'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'hired_at'       => ['nullable', 'date'],
            'payment_type'   => ['nullable', 'in:hourly,daily'],
            'hourly_rate'    => ['nullable', 'numeric', 'min:0'],
            'daily_rate'     => ['nullable', 'numeric', 'min:0'],
        ]);

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
            ]);

            DB::commit();

            // 4) Enviar correo de bienvenida (fuera de la transacción)
            $empresa = Empresa::find($empresaId);
            $documentos = is_array($empresa->documentos) ? $empresa->documentos : [];

            $emailSent = false;
            $emailError = null;

            // Preparar adjuntos descargando desde S3
            $attachments = [];
            foreach ($documentos as $doc) {
                if (empty($doc['url']) || empty($doc['nombre']) || empty($doc['path'])) continue;
                try {
                    // Descargar el archivo desde S3 usando el path
                    $contenido = \Illuminate\Support\Facades\Storage::disk('s3')->get($doc['path']);
                    $attachments[] = [
                        'filename' => $doc['nombre'] . '.pdf',
                        'content'  => base64_encode($contenido),
                    ];
                } catch (\Exception $e) {
                    Log::warning("No se pudo adjuntar documento {$doc['nombre']}: " . $e->getMessage());
                }
            }

            try {
                Log::info('Intentando enviar correo a: ' . $newUser->email);
                Log::info('URL del frontend configurada: ' . config('app.frontend_url', 'https://kore-react-frontend.vercel.app'));
                
                \Resend\Laravel\Facades\Resend::emails()->send([
                    'from'    => config('mail.from.name') . ' <' . config('mail.from.address') . '>',
                    'to'      => [$newUser->email],
                    'subject' => "¡Bienvenido a {$empresa->name}! Tus credenciales de acceso",
                    'html'    => view('emails.bienvenida-empleado', [
                        'empleadoNombre'   => $newUser->name,
                        'empresaNombre'    => $empresa->name,
                        'email'            => $newUser->email,
                        'passwordTemporal' => $passwordTemporal,
                        'appUrl'           => config('app.frontend_url', 'https://kore-react-frontend.vercel.app'),
                        'documentos'       => $documentos,
                    ])->render(),
                    'attachments' => $attachments,
                ]);
                
                Log::info('Correo enviado exitosamente');
                $emailSent = true;
            } catch (\Exception $e) {
                Log::error('ERROR enviando correo: ' . $e->getMessage());
                $emailError = $e->getMessage();
                $emailSent = false;
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al crear usuario', 'detail' => $e->getMessage()], 500);
        }

        $response = ['item' => $this->present($newUser, $emp), 'email_sent' => $emailSent];

        if (!$emailSent) {
            $response['warning'] = 'El usuario fue creado pero no se pudo enviar el correo de bienvenida. Comparte las credenciales manualmente.';
            $response['credenciales'] = [
                'email'    => $newUser->email,
                'password' => $passwordTemporal,
            ];
        }

        return response()->json($response, 201);
    }

    /**
     * Actualizar usuario (admin).
     * Actualiza User y su Empleado vinculado.
     */
    public function update(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $target = User::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (!$target) return response()->json(['message' => 'Usuario no encontrado'], 404);

        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:160'],
            'email'          => ['sometimes', 'email', 'max:200', Rule::unique('users', 'email')->ignore($target->id)],
            'password'       => ['sometimes', 'nullable', 'string', 'min:6', 'max:100'],
            'role'           => ['sometimes', Rule::in(['admin', 'supervisor', 'empleado'])],
            'is_active'      => ['sometimes', 'boolean'],
            // Campos del empleado
            'position_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employee_code'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'hired_at'       => ['sometimes', 'nullable', 'date'],
            'payment_type'   => ['sometimes', 'nullable', 'in:hourly,daily'],
            'hourly_rate'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'daily_rate'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

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
                if (isset($data['is_active']))      $emp->status = $data['is_active'] ? 'active' : 'inactive';
                $emp->save();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al actualizar', 'detail' => $e->getMessage()], 500);
        }

        return response()->json(['item' => $this->present($target, $emp ?? null)]);
    }

    /**
     * Toggle activo/inactivo (admin).
     */
    public function toggleStatus(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

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

        return response()->json([
            'message'   => $target->is_active ? 'Usuario activado' : 'Usuario desactivado',
            'item'      => $this->present($target, $emp),
        ]);
    }

    /**
     * Eliminar usuario y empleado vinculado (admin).
     */
    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

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
            return response()->json(['message' => 'Error al eliminar empleado', 'detail' => $e->getMessage()], 500);
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
        ];
    }
    private function generarPasswordTemporal(): string
    {
        return Str::password(12, letters: true, numbers: true, symbols: true, spaces: false);
    }
}
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\V1\RegisterRequest;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\User;
use App\Traits\HandlesLoginLockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Controlador de autenticación stateful para el frontend principal Kore.
 *
 * Usa sesiones de Laravel + cookies HttpOnly en lugar de tokens Bearer.
 * Requiere que frontend y backend compartan dominio raíz para que la cookie
 * de sesión se envíe en peticiones cross-origin credentialed.
 */
class WebAuthController extends Controller
{
    use HandlesLoginLockout;

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Timing-attack mitigation
        if (! $user) {
            Hash::check($data['password'], '$2y$12$dummyhashvaluetopreventtimingattacksx');

            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if ($message = $this->accountIsLocked($user)) {
            return response()->json(['message' => $message], 423);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Tu correo electrónico aún no ha sido verificado. Revisa tu bandeja de entrada.',
                'requires_email_verification' => true,
            ], 403);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Tu cuenta aún no ha sido activada. Revisa tu correo electrónico y usa el enlace de activación.',
                'requires_activation' => true,
            ], 403);
        }

        if (! Hash::check($data['password'], $user->password)) {
            $this->recordFailedLogin($user);

            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        $this->resetFailedLoginAttempts($user);

        Auth::login($user, $request->boolean('remember', false));
        $request->session()->regenerate();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'section' => $user->section,
                'empresa_id' => $user->empresa_id,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout OK']);
    }

    public function me(Request $request)
    {
        $u = $request->user();

        if (! $u) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $empresa = null;
        $enabledKeys = [];

        if ($u->empresa_id) {
            $empresa = Empresa::find($u->empresa_id);

            $enabledKeys = DB::table('empresa_modules')
                ->where('empresa_id', $u->empresa_id)
                ->where('enabled', true)
                ->pluck('module_slug')
                ->values()
                ->all();
        }

        return response()->json([
            'user' => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'section' => $u->section,
                'empresa_id' => $u->empresa_id,
            ],
            'empresa' => $empresa ? [
                'id' => $empresa->id,
                'name' => $empresa->name,
                'slug' => $empresa->slug,
                'palette_key' => $empresa->palette_key,
                'status' => $empresa->status,
            ] : null,
            'features' => [
                'enabled_modules' => $enabledKeys,
            ],
        ]);
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            abort(403, 'Enlace de verificación no válido.');
        }

        if (! URL::hasValidSignature($request)) {
            abort(403, 'El enlace de verificación ha expirado.');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->away(config('app.frontend_url').'?already_verified=1');
        }

        $user->markEmailAsVerified();

        return redirect()->away(config('app.frontend_url').'?verified=1');
    }

    public function resendVerificationEmail(Request $request)
    {
        // Si hay sesión activa, usamos el usuario autenticado
        $user = $request->user();

        // Si no hay sesión, buscamos por email en el body
        if (! $user) {
            $data = $request->validate(['email' => ['required', 'email']]);
            $user = User::where('email', $data['email'])->first();

            if (! $user) {
                // No revelar si el email existe o no
                return response()->json(['message' => 'Si el correo existe, recibirás un enlace de verificación.']);
            }
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'El correo ya está verificado.'], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Se ha reenviado el correo de verificación.']);
    }

    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $slug = Str::slug($data['empresa_nombre']).'-'.Str::lower(Str::random(6));

            $empresa = Empresa::create([
                'name' => $data['empresa_nombre'],
                'slug' => $slug,
                'status' => 'active',
                'plan' => 'starter',
                'industry' => $data['industry'] ?? null,
                'employee_count_range' => $data['employee_count_range'] ?? null,
                'settings' => [
                    'calendar' => ['week_start' => 0],
                ],
            ]);

            $user = User::create([
                'empresa_id' => $empresa->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role' => 'admin',
                'is_active' => true,
            ]);

            $empleado = Empleado::create([
                'empresa_id' => $empresa->id,
                'user_id' => $user->id,
                'full_name' => $data['admin_name'],
                'status' => 'active',
                'payment_type' => 'hourly',
            ]);

            $selectedModules = $data['modules'] ?? [];
            $defaultModules = ['tareas', 'asistencia', 'nomina', 'configuracion', 'gondolas', 'semaforo'];

            $allModules = array_unique(array_merge($selectedModules, $defaultModules));

            $modulesToInsert = [];
            foreach ($allModules as $mod) {
                $modulesToInsert[] = [
                    'id' => (string) Str::uuid(),
                    'empresa_id' => $empresa->id,
                    'module_slug' => $mod,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('empresa_modules')->insert($modulesToInsert);

            DB::commit();

            // No iniciamos sesión automáticamente hasta que el correo sea verificado.
            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'Registro exitoso. Revisa tu correo electrónico para activar tu cuenta.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'section' => $user->section,
                    'empresa_id' => $user->empresa_id,
                ],
                'empresa' => [
                    'id' => $empresa->id,
                    'name' => $empresa->name,
                    'plan' => $empresa->plan,
                    'industry' => $empresa->industry,
                    'employee_count_range' => $empresa->employee_count_range,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error en el registro: '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['message' => 'Error en el registro. Inténtalo de nuevo más tarde.'], 500);
        }
    }
}

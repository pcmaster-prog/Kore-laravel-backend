<?php

// AuthController: manejo de autenticación, generación de token, endpoint para obtener datos de usuario autenticado y empresa asociada

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use App\Traits\HandlesLoginLockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use HandlesLoginLockout;

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Timing-attack mitigation: always run Hash::check even if user doesn't exist
        if (! $user) {
            Hash::check($data['password'], '$2y$12$dummyhashvaluetopreventtimingattacksx');

            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if ($message = $this->accountIsLocked($user)) {
            return response()->json(['message' => $message], 423);
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

        $token = $user->createToken('kore-api')->plainTextToken;

        return response()->json([
            'token' => $token,
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

    public function me(Request $request)
    {
        $u = $request->user();
        $u->loadMissing('empleado');

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
                'empleado' => $u->empleado ? [
                    'id' => $u->empleado->id,
                    'full_name' => $u->empleado->full_name,
                ] : null,
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

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout OK']);
    }
}

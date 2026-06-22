<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserActivationToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserActivationController extends Controller
{
    /**
     * Valida un token de activación y devuelve datos básicos del usuario.
     */
    public function check(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
        ]);

        $activation = UserActivationToken::where('token', $validated['token'])
            ->with('user')
            ->first();

        if (! $activation || $activation->isExpired()) {
            return response()->json(['message' => 'El enlace de activación no es válido o ha expirado.'], 400);
        }

        return response()->json([
            'valid' => true,
            'user' => [
                'id' => $activation->user->id,
                'name' => $activation->user->name,
                'email' => $activation->user->email,
            ],
        ]);
    }

    /**
     * Establece la contraseña del usuario y activa la cuenta.
     */
    public function activate(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $activation = UserActivationToken::where('token', $validated['token'])
            ->with('user')
            ->first();

        if (! $activation || $activation->isExpired()) {
            return response()->json(['message' => 'El enlace de activación no es válido o ha expirado.'], 400);
        }

        $user = $activation->user;

        $user->update([
            'password' => Hash::make($validated['password']),
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $activation->delete();

        return response()->json([
            'message' => 'Cuenta activada correctamente. Ya puedes iniciar sesión.',
        ]);
    }
}

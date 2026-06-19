<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request)
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request)
    {
        try {
            // Socialite con stateful driver valida automáticamente el parámetro ?state
            // contra el valor guardado en sesión. No usamos stateless() para evitar
            // ataques CSRF en el flujo OAuth.
            $googleUser = Socialite::driver('google')->user();

            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make(Str::random(24)),
                    'role' => 'aspirante',
                    'provider' => 'google',
                    'provider_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                ]);
            } else {
                // Solo actualizamos avatar; no sobreescribimos provider si ya existe otro.
                $user->update([
                    'avatar' => $googleUser->getAvatar(),
                ]);
            }

            $token = $user->createToken('portal_token')->plainTextToken;

            $frontendUrl = rtrim(config('services.google.frontend_portal_url', config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')), '/') . '/auth/google/callback';

            // Enviamos token y datos en el fragmento (#) para que no queden en logs ni referrer.
            $fragment = 'token=' . urlencode($token) . '&user=' . urlencode(json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ]));

            $redirectUrl = $frontendUrl . '#' . $fragment;
            return redirect()->away($redirectUrl);

        } catch (\Exception $e) {
            Log::error("Google Auth Error: " . $e->getMessage());
            $errorUrl = rtrim(config('services.google.frontend_portal_url', config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')), '/') . '/login?error=auth_failed';
            return redirect()->away($errorUrl);
        }
    }
}

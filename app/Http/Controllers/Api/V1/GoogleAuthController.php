<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PortalCookieAuth;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirige al usuario a Google OAuth.
     * Acepta un state opcional del frontend para mitigar CSRF.
     */
    public function redirect(Request $request)
    {
        $state = $request->query('state');

        if ($state) {
            session(['portal_oauth_state' => $state]);
        } else {
            $request->session()->forget('portal_oauth_state');
        }

        $driver = Socialite::driver('google');

        if ($state) {
            $driver->with(['state' => $state]);
        }

        return $driver->redirect();
    }

    /**
     * Callback de Google OAuth.
     * Crea/actualiza el usuario, genera un token Sanctum y lo envía al
     * frontend en una cookie HttpOnly. No expone el token en la URL.
     */
    public function callback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $user = User::where('email', $googleUser->getEmail())->first();

            if (! $user) {
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
                $user->update([
                    'avatar' => $googleUser->getAvatar(),
                ]);
            }

            $token = $user->createToken('portal_token')->plainTextToken;

            $frontendUrl = rtrim(config('services.google.frontend_portal_url', config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')), '/')
                .'/auth/google/callback';

            $state = $request->input('state', session('portal_oauth_state'));
            $request->session()->forget('portal_oauth_state');

            $redirectUrl = $state
                ? $frontendUrl.'?state='.urlencode($state)
                : $frontendUrl;

            $cookie = Cookie::make(
                PortalCookieAuth::COOKIE_NAME,
                $token,
                60 * 24 * 7, // 7 días
                '/',
                null,
                config('session.secure', true),
                true, // HttpOnly
                false,
                config('session.same_site', 'lax')
            );

            return redirect()->away($redirectUrl)->withCookie($cookie);

        } catch (\Exception $e) {
            Log::error('Google Auth Error: '.$e->getMessage());

            $errorUrl = rtrim(config('services.google.frontend_portal_url', config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')), '/')
                .'/login?error=auth_failed';

            return redirect()->away($errorUrl);
        }
    }
}

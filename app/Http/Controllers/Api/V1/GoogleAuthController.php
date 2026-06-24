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
    private const STATE_COOKIE_NAME = 'portal_oauth_state';

    /**
     * Redirige al usuario a Google OAuth.
     * Acepta un state opcional del frontend para mitigar CSRF.
     *
     * Guardamos el state tanto en sesión como en una cookie propia para
     * robustecer el callback cuando la sesión Laravel no persiste entre la
     * salida a Google y el regreso (navegadores, SameSite, etc.).
     */
    public function redirect(Request $request)
    {
        $state = $request->query('state');

        if ($state) {
            session([self::STATE_COOKIE_NAME => $state]);
        } else {
            $request->session()->forget(self::STATE_COOKIE_NAME);
        }

        $driver = Socialite::driver('google')->stateless();

        if ($state) {
            $driver->with(['state' => $state]);
        }

        // Cookie corta (10 min) solo para validar el callback. Es HttpOnly
        // para evitar que JS la lea, pero se envía automáticamente al backend.
        $stateCookie = Cookie::make(
            self::STATE_COOKIE_NAME,
            $state ?? '',
            10,
            '/',
            null,
            config('session.secure', true),
            true,
            false,
            config('session.same_site', 'lax')
        );

        return $driver->redirect()->withCookie($stateCookie);
    }

    /**
     * Callback de Google OAuth.
     * Crea/actualiza el usuario, genera un token Sanctum y lo envía al
     * frontend. Si el backend y el frontend comparten dominio raíz, el token
     * viaja en una cookie HttpOnly. Si no, se envía como fragmento de URL para
     * que el frontend lo use como Bearer token.
     */
    public function callback(Request $request)
    {
        $frontendUrl = rtrim(config(
            'services.google.frontend_portal_url',
            config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')
        ), '/');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Validación manual del state anti-CSRF. Usamos cookie propia como
            // principal y sesión como respaldo.
            $state = $request->input('state');
            $expectedState = $request->cookie(self::STATE_COOKIE_NAME)
                ?? session(self::STATE_COOKIE_NAME);

            $request->session()->forget(self::STATE_COOKIE_NAME);

            if (! $state || ! $expectedState || ! hash_equals((string) $expectedState, (string) $state)) {
                throw new \Exception('La validación de seguridad OAuth (state) falló.');
            }

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

            $redirectPath = '/auth/google/callback' . ($state ? '?state=' . urlencode($state) : '');
            $redirectUrl = $frontendUrl . $redirectPath;

            $canShareCookie = $this->canShareCookie($frontendUrl);
            $cookieDomain = $canShareCookie ? $this->sharedCookieDomain($frontendUrl) : null;

            $tokenCookie = Cookie::make(
                PortalCookieAuth::COOKIE_NAME,
                $token,
                60 * 24 * 7, // 7 días
                '/',
                $cookieDomain,
                config('session.secure', true),
                true, // HttpOnly
                false,
                config('session.same_site', 'lax')
            );

            // Borramos la cookie de state para no dejarla viva.
            $stateCookie = Cookie::forget(self::STATE_COOKIE_NAME, '/');

            // Si no podemos compartir la cookie entre dominios, enviamos el token
            // como fragmento de URL para que el frontend lo use como Bearer token.
            if (! $canShareCookie) {
                $redirectUrl .= '#portal_token=' . urlencode($token);
            }

            Log::info('Portal OAuth exitoso', [
                'user_id' => $user->id,
                'email' => $user->email,
                'shared_cookie' => $canShareCookie,
                'cookie_domain' => $cookieDomain,
            ]);

            return redirect()->away($redirectUrl)
                ->withCookie($tokenCookie)
                ->withCookie($stateCookie);

        } catch (\Exception $e) {
            Log::error('Google Auth Error: ' . $e->getMessage(), [
                'url' => $request->fullUrl(),
                'code' => $request->input('code'),
                'state' => $request->input('state'),
                'frontend' => $frontendUrl,
            ]);

            $errorUrl = $frontendUrl . '/login?error=auth_failed&message=' . urlencode($e->getMessage());

            return redirect()->away($errorUrl)
                ->withCookie(Cookie::forget(self::STATE_COOKIE_NAME, '/'));
        }
    }

    /**
     * Determina si backend y frontend pueden compartir una cookie.
     * Requiere que ambos estén bajo el mismo dominio raíz (eTLD+1 simple).
     */
    private function canShareCookie(string $frontendUrl): bool
    {
        $frontendHost = parse_url($frontendUrl, PHP_URL_HOST);
        $backendHost = parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST);

        if (! $frontendHost || ! $backendHost) {
            return false;
        }

        return $this->rootDomain($frontendHost) === $this->rootDomain($backendHost);
    }

    /**
     * Devuelve el dominio compartido con punto inicial para que la cookie sea
     * enviada entre subdominios (p. ej. .decorartereposteria.mx).
     */
    private function sharedCookieDomain(string $frontendUrl): ?string
    {
        $host = parse_url($frontendUrl, PHP_URL_HOST);

        if (! $host || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return '.' . $this->rootDomain($host);
    }

    /**
     * Extrae el dominio raíz usando las dos últimas partes del host.
     * Suficiente para dominios tipo ejemplo.mx, api.ejemplo.com, etc.
     */
    private function rootDomain(string $host): string
    {
        $parts = explode('.', $host);

        if (count($parts) <= 2) {
            return $host;
        }

        return implode('.', array_slice($parts, -2));
    }
}

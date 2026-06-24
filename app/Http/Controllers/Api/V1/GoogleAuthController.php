<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PortalCookieAuth;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    private const STATE_COOKIE_NAME = 'portal_oauth_state';

    /**
     * Configuración pública de OAuth (sin secretos) para depuración.
     */
    public function config()
    {
        $frontendUrl = rtrim(config(
            'services.google.frontend_portal_url',
            config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')
        ), '/');

        $redirectUri = config('services.google.redirect');

        return response()->json([
            'app_url' => config('app.url'),
            'frontend_portal_url' => $frontendUrl,
            'google_redirect_uri' => $redirectUri,
            'can_share_cookie' => $this->canShareCookie($frontendUrl),
            'shared_cookie_domain' => $this->sharedCookieDomain($frontendUrl),
        ]);
    }

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
        $state = $request->query('state') ?: Str::random(40);

        session([self::STATE_COOKIE_NAME => $state]);

        $redirectUri = config('services.google.redirect');
        $frontendUrl = rtrim(config(
            'services.google.frontend_portal_url',
            config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')
        ), '/');

        $googleUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        Log::info('Portal OAuth redirect', [
            'redirect_uri' => $redirectUri,
            'frontend' => $frontendUrl,
            'state' => $state,
        ]);

        // Cookie corta (10 min) solo para validar el callback. Es HttpOnly
        // para evitar que JS la lea, pero se envía automáticamente al backend.
        $stateCookie = Cookie::make(
            self::STATE_COOKIE_NAME,
            $state,
            10,
            '/',
            null,
            config('session.secure', true),
            true,
            false,
            config('session.same_site', 'lax')
        );

        return redirect()->away($googleUrl)->withCookie($stateCookie);
    }

    /**
     * Callback de Google OAuth.
     * Crea/actualiza el usuario, genera un token Sanctum y lo envía al
     * frontend. Si el backend y el frontend comparten dominio raíz, el token
     * viaja en una cookie HttpOnly. Si no, se envía como parámetro de URL para
     * que el frontend lo use como Bearer token.
     */
    public function callback(Request $request)
    {
        $frontendUrl = rtrim(config(
            'services.google.frontend_portal_url',
            config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')
        ), '/');

        try {
            Log::info('Portal OAuth callback received', [
                'input' => $request->only(['code', 'state', 'error', 'error_description']),
                'frontend' => $frontendUrl,
                'redirect_uri' => config('services.google.redirect'),
            ]);

            if ($request->filled('error')) {
                throw new \Exception('Google devolvió un error: ' . $request->input('error_description', $request->input('error')));
            }

            // Validación manual del state anti-CSRF. Usamos cookie propia como
            // principal y sesión como respaldo.
            $state = $request->input('state');
            $expectedState = $request->cookie(self::STATE_COOKIE_NAME)
                ?? session(self::STATE_COOKIE_NAME);

            $request->session()->forget(self::STATE_COOKIE_NAME);

            if (! $state || ! $expectedState || ! hash_equals((string) $expectedState, (string) $state)) {
                throw new \Exception('La validación de seguridad OAuth (state) falló. State recibido: ' . ($state ?: 'vacío'));
            }

            $code = $request->input('code');
            if (! $code) {
                throw new \Exception('Google no envió el parámetro code.');
            }

            // Intercambiamos el code por tokens directamente con Google.
            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect'),
                'grant_type' => 'authorization_code',
            ]);

            if ($tokenResponse->failed()) {
                throw new \Exception('Google token exchange failed: ' . $tokenResponse->body());
            }

            $accessToken = $tokenResponse->json('access_token');
            if (! $accessToken) {
                throw new \Exception('Google no devolvió access_token.');
            }

            $userInfoResponse = Http::get('https://www.googleapis.com/oauth2/v3/userinfo', [
                'access_token' => $accessToken,
            ]);

            if ($userInfoResponse->failed()) {
                throw new \Exception('Google userinfo failed: ' . $userInfoResponse->body());
            }

            $googleUser = $userInfoResponse->json();
            $email = $googleUser['email'] ?? null;
            $name = $googleUser['name'] ?? ($email ? explode('@', $email)[0] : 'Usuario');
            $avatar = $googleUser['picture'] ?? null;
            $providerId = $googleUser['sub'] ?? null;

            if (! $email) {
                throw new \Exception('Google no devolvió email.');
            }

            $user = User::where('email', $email)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(24)),
                    'role' => 'aspirante',
                    'provider' => 'google',
                    'provider_id' => $providerId,
                    'avatar' => $avatar,
                ]);
            } else {
                $user->update([
                    'avatar' => $avatar,
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
            // como parámetro de URL para que el frontend lo use como Bearer token.
            if (! $canShareCookie) {
                $redirectUrl .= (str_contains($redirectUrl, '?') ? '&' : '?') . 'portal_token=' . urlencode($token);
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
            Log::error('Google Auth Error', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'url' => $request->fullUrl(),
                'input' => $request->only(['code', 'state', 'error', 'error_description']),
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

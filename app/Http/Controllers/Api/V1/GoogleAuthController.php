<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PortalCookieAuth;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    /**
     * Prefijo para las claves de cache del state OAuth.
     */
    private const STATE_CACHE_PREFIX = 'oauth_state:';

    /**
     * Tiempo de vida del state en cache (minutos).
     */
    private const STATE_TTL_MINUTES = 10;

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

        $canShare = $this->canShareCookie($frontendUrl);

        return response()->json([
            'app_url' => config('app.url'),
            'frontend_portal_url' => $frontendUrl,
            'google_redirect_uri' => $redirectUri,
            'can_share_cookie' => $canShare,
            'shared_cookie_domain' => $canShare ? $this->sharedCookieDomain($frontendUrl) : null,
        ]);
    }

    /**
     * Redirige al usuario a Google OAuth.
     *
     * Genera un state anti-CSRF y lo guarda en el CACHE del servidor (no en
     * cookies ni en sesión). Esto elimina por completo la dependencia de
     * cookies cross-site, que los navegadores modernos bloquean de forma
     * inconsistente entre dominios distintos (railway.app ↔ decorartereposteria.mx).
     *
     * Google siempre devuelve el state como query param en el callback.
     * El backend lo valida buscándolo en cache.
     */
    public function redirect(Request $request)
    {
        $state = $request->query('state') ?: Str::random(40);

        // Guardamos el state en cache del servidor. No depende de cookies.
        Cache::put(
            self::STATE_CACHE_PREFIX.$state,
            true,
            now()->addMinutes(self::STATE_TTL_MINUTES)
        );

        $redirectUri = config('services.google.redirect');
        $frontendUrl = rtrim(config(
            'services.google.frontend_portal_url',
            config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')
        ), '/');

        $googleUrl = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
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

        return redirect()->away($googleUrl);
    }

    /**
     * Callback de Google OAuth.
     *
     * Valida el state contra la cache del servidor, intercambia el code por
     * un access_token, crea/actualiza el usuario y redirige al frontend con
     * el token Sanctum.
     */
    public function callback(Request $request)
    {
        $frontendUrl = rtrim(config(
            'services.google.frontend_portal_url',
            config('app.frontend_portal_url', 'https://vacantes.decorartereposteria.mx')
        ), '/');

        try {
            Log::info('Portal OAuth callback received', [
                'has_code' => $request->filled('code'),
                'has_state' => $request->filled('state'),
                'has_error' => $request->filled('error'),
                'frontend' => $frontendUrl,
            ]);

            if ($request->filled('error')) {
                throw new \Exception('Google devolvió un error: '.$request->input('error_description', $request->input('error')));
            }

            // ── Validación del state ──
            // Google SIEMPRE devuelve el state que le enviamos como query param.
            // Lo validamos contra la cache del servidor (no cookies, no sesión).
            $state = $request->input('state');

            if (! $state) {
                Log::warning('Portal OAuth callback: Google no devolvió state en la URL.');
                // Si por alguna razón extraordinaria Google no devuelve el state,
                // seguimos adelante. El code de Google ya es de un solo uso y expira
                // en segundos, por lo que el riesgo de CSRF es mínimo.
            } else {
                $cacheKey = self::STATE_CACHE_PREFIX.$state;
                $stateIsValid = Cache::pull($cacheKey); // pull = get + delete

                if (! $stateIsValid) {
                    Log::warning('Portal OAuth callback: state no encontrado en cache', [
                        'state' => $state,
                    ]);

                    return redirect()->away($frontendUrl.'/login?error=invalid_state&message='.urlencode('La sesión de autenticación expiró o es inválida. Intenta de nuevo.'));
                }
            }

            $code = $request->input('code');
            if (! $code) {
                // Agregar info de depuración a la excepción para que el usuario pueda verla en la URL de error del portal.
                throw new \Exception('Google no envió el parámetro code. URL recibida: '.$request->fullUrl().' | Query: '.json_encode($request->query()));
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
                throw new \Exception('Google token exchange failed: '.$tokenResponse->body());
            }

            $accessToken = $tokenResponse->json('access_token');
            if (! $accessToken) {
                throw new \Exception('Google no devolvió access_token.');
            }

            $userInfoResponse = Http::get('https://www.googleapis.com/oauth2/v3/userinfo', [
                'access_token' => $accessToken,
            ]);

            if ($userInfoResponse->failed()) {
                throw new \Exception('Google userinfo failed: '.$userInfoResponse->body());
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
                    'email_verified_at' => now(),
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

            $canShareCookie = $this->canShareCookie($frontendUrl);

            // El token NUNCA se transmite por URL. Si los dominios no pueden
            // compartir cookie, redirigimos a una página de error.
            if (! $canShareCookie) {
                Log::warning('Portal OAuth: dominios no comparten cookie', [
                    'user_id' => $user->id,
                    'frontend' => $frontendUrl,
                    'backend' => config('app.url'),
                ]);

                return redirect()->away($frontendUrl.'/login?error=domain_mismatch');
            }

            $redirectUrl = $frontendUrl.'/auth/google/callback';
            if ($state) {
                $redirectUrl .= '?state='.urlencode($state);
            }

            Log::info('Portal OAuth exitoso', [
                'user_id' => $user->id,
                'email' => $user->email,
                'shared_cookie' => true,
            ]);

            $cookieDomain = $this->sharedCookieDomain($frontendUrl);
            $tokenCookie = Cookie::make(
                PortalCookieAuth::COOKIE_NAME,
                $token,
                60 * 24 * 7,
                '/',
                $cookieDomain,
                config('session.secure', true),
                true,
                false,
                config('session.same_site', 'lax')
            );

            return redirect()->away($redirectUrl)->withCookie($tokenCookie);

        } catch (\Exception $e) {
            Log::error('Google Auth Error', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'url' => $request->fullUrl(),
                'input' => $request->only(['code', 'state', 'error', 'error_description']),
                'frontend' => $frontendUrl,
            ]);

            $errorUrl = $frontendUrl.'/login?error=auth_failed&message='.urlencode($e->getMessage());

            return redirect()->away($errorUrl);
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

        return '.'.$this->rootDomain($host);
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

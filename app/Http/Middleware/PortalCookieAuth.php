<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al usuario del portal leyendo el token Sanctum desde una
 * cookie HttpOnly llamada "portal_token".
 *
 * Evita almacenar el token en localStorage o en la URL del navegador.
 */
class PortalCookieAuth
{
    public const COOKIE_NAME = 'portal_token';

    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->cookie(self::COOKIE_NAME);

        // Fallback: permitir enviar el token como Bearer Authorization cuando
        // backend y frontend no comparten dominio raíz y no es posible usar
        // una cookie HttpOnly compartida.
        if (! $plainTextToken) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
                $plainTextToken = $matches[1];
            }
        }

        if (! $plainTextToken || ! str_contains($plainTextToken, '|')) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        [$id, $token] = explode('|', $plainTextToken, 2);

        $accessToken = PersonalAccessToken::find($id);

        if (! $accessToken || ! hash_equals($accessToken->token, hash('sha256', $token))) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return response()->json(['message' => 'Sesión expirada.'], 401);
        }

        $user = $accessToken->tokenable;

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}

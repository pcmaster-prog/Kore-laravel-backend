<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PortalCookieAuth;
use App\Models\Empresa;
use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

/**
 * Auth endpoints específicos para el portal de vacantes.
 * Usan cookie HttpOnly en lugar de Bearer tokens.
 */
class PortalAuthController extends Controller
{
    /**
     * Devuelve los datos del usuario autenticado vía cookie.
     */
    public function me(Request $request)
    {
        $u = $request->user();

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
                'avatar' => $u->avatar,
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

    /**
     * Cierra la sesión del portal revocando el token y borrando la cookie.
     */
    public function logout(Request $request)
    {
        $plainTextToken = $request->cookie(PortalCookieAuth::COOKIE_NAME);

        if ($plainTextToken && str_contains($plainTextToken, '|')) {
            [$id] = explode('|', $plainTextToken, 2);
            $accessToken = PersonalAccessToken::find($id);

            if ($accessToken) {
                $accessToken->delete();
            }
        }

        $cookie = Cookie::forget(PortalCookieAuth::COOKIE_NAME, '/');

        return response()->json(['message' => 'Logout OK'])->withCookie($cookie);
    }
}

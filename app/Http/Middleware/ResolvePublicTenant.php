<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tenant para endpoints públicos del portal de vacantes.
 *
 * Estrategia: Host header → lookup en empresas.domain (whitelist implícita).
 * Cualquier host no registrado en la tabla empresas devuelve 404 limpio.
 *
 * En entornos local/testing usa la primera empresa disponible para no
 * bloquear el desarrollo.
 */
class ResolvePublicTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local', 'testing')) {
            // Bypass para desarrollo — usa slug de config si está definido,
            // si no, la primera empresa disponible.
            $slug = config('app.default_empresa_slug');
            $empresa = $slug
                ? Empresa::where('slug', $slug)->firstOrFail()
                : Empresa::firstOrFail();
        } else {
            // Producción: lookup por X-Tenant-Host o parseando Origin / Referer
            $host = $request->header('X-Tenant-Host');
            if (!$host && $request->header('Origin')) {
                $host = parse_url($request->header('Origin'), PHP_URL_HOST);
            }
            if (!$host && $request->header('Referer')) {
                $host = parse_url($request->header('Referer'), PHP_URL_HOST);
            }
            if (!$host) {
                $host = $request->getHost(); // Fallback
            }

            $empresa = Empresa::where('domain', $host)->firstOrFail();
        }

        // Almacenar en request para que el controller lo lea sin repetir la query
        $request->attributes->set('public_empresa_id', $empresa->id);
        $request->attributes->set('public_empresa', $empresa);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tenant para endpoints públicos del portal de vacantes.
 *
 * Estrategia en cascada (primer match gana):
 *   1. X-Tenant-Host header  → lookup por empresas.domain
 *   2. Origin header         → lookup por empresas.domain
 *   3. Referer header        → lookup por empresas.domain
 *   4. Host header           → lookup por empresas.domain
 *   5. empresa_slug query    → lookup por empresas.slug
 *   6. default_empresa_slug  → lookup por empresas.slug desde config
 *   7. Primera empresa       → fallback seguro (single-tenant)
 */
class ResolvePublicTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $empresa = $this->resolveEmpresa($request);

        if (! $empresa) {
            Log::warning('ResolvePublicTenant: no se pudo resolver ninguna empresa', [
                'url'             => $request->fullUrl(),
                'x_tenant_host'   => $request->header('X-Tenant-Host'),
                'origin'          => $request->header('Origin'),
                'referer'         => $request->header('Referer'),
                'host'            => $request->getHost(),
            ]);

            return response()->json(['message' => 'Empresa no encontrada'], 404);
        }

        $request->attributes->set('public_empresa_id', $empresa->id);
        $request->attributes->set('public_empresa', $empresa);

        return $next($request);
    }

    private function resolveEmpresa(Request $request): ?Empresa
    {
        // --- 1. X-Tenant-Host header ---
        if ($host = $request->header('X-Tenant-Host')) {
            $empresa = Empresa::where('domain', $host)->first();
            if ($empresa) return $empresa;
        }

        // --- 2. Origin header ---
        if ($origin = $request->header('Origin')) {
            $host = parse_url($origin, PHP_URL_HOST);
            if ($host) {
                $empresa = Empresa::where('domain', $host)->first();
                if ($empresa) return $empresa;
            }
        }

        // --- 3. Referer header ---
        if ($referer = $request->header('Referer')) {
            $host = parse_url($referer, PHP_URL_HOST);
            if ($host) {
                $empresa = Empresa::where('domain', $host)->first();
                if ($empresa) return $empresa;
            }
        }

        // --- 4. Host header del servidor ---
        $host = $request->getHost();
        $empresa = Empresa::where('domain', $host)->first();
        if ($empresa) return $empresa;

        // --- 5. Query param empresa_slug ---
        if ($request->filled('empresa_slug')) {
            $empresa = Empresa::where('slug', $request->input('empresa_slug'))->first();
            if ($empresa) return $empresa;
        }

        // --- 6. Config por defecto (APP_DEFAULT_EMPRESA_SLUG en .env) ---
        $defaultSlug = config('app.default_empresa_slug');
        if ($defaultSlug) {
            $empresa = Empresa::where('slug', $defaultSlug)->first();
            if ($empresa) return $empresa;
        }

        // --- 7. Última opción: única empresa en el sistema (single-tenant) ---
        $count = Empresa::count();
        if ($count === 1) {
            return Empresa::first();
        }

        return null;
    }
}

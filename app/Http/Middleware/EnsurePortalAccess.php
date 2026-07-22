<?php

namespace App\Http\Middleware;

use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalAccess
{
    /**
     * Handle an incoming request.
     *
     * Permite aspirantes y también a candidatos ya contratados (su rol cambia a
     * empleado_prueba/empleado al aceptar la oferta, pero aún necesitan el portal
     * para subir sus documentos de onboarding).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Acceso denegado. Solo aspirantes.'], 403);
        }

        if ($user->role !== 'aspirante' && ! Application::where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Acceso denegado. Solo aspirantes.'], 403);
        }

        return $next($request);
    }
}

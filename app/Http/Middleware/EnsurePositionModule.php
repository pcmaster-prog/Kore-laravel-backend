<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePositionModule
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $moduleSlug): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // El usuario debe tener un empleado asociado, el empleado debe tener un position, y ese position debe tener el modulo
        // Para admins, podríamos permitir todo. Para esto, revisemos el rol.
        if ($user->role === 'admin') {
            return $next($request);
        }

        $empleado = $user->empleado;
        if (! $empleado || ! $empleado->position_id) {
            return response()->json(['message' => 'No tienes un puesto asignado.'], 403);
        }

        $position = $empleado->position;
        $hasModule = $position->modules()->where('module_slug', $moduleSlug)->exists();

        if (! $hasModule) {
            return response()->json(['message' => "Acceso denegado: requieres el módulo {$moduleSlug}"], 403);
        }

        return $next($request);
    }
}

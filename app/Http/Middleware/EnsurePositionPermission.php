<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que el empleado autenticado tenga un permiso granular dentro de un
 * módulo asignado a su puesto. Los administradores siempre pasan.
 *
 * Uso: ->middleware('position.permission:produccion_maderas,ensamblaje')
 *
 * Lógica de compatibilidad hacia atrás:
 * - Si el puesto no tiene permisos configurados, se permite el acceso.
 * - Si el módulo no tiene permisos configurados, se permite el acceso.
 * - Solo se niega cuando el permiso específico NO está en la lista.
 */
class EnsurePositionPermission
{
    public function handle(Request $request, Closure $next, string $moduleSlug, string $permissionKey): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // Los administradores tienen acceso total.
        if ($user->role === 'admin') {
            return $next($request);
        }

        $empleado = $user->empleado;

        if (! $empleado || ! $empleado->position_id) {
            return response()->json(['message' => 'No tienes un puesto asignado'], 403);
        }

        $position = $empleado->position;

        if (! $position) {
            return response()->json(['message' => 'Puesto no encontrado'], 403);
        }

        $permissions = $position->permissions ?? [];

        // Compatibilidad hacia atrás: sin configuración de permisos = acceso total.
        if (empty($permissions) || ! array_key_exists($moduleSlug, $permissions)) {
            return $next($request);
        }

        $modulePermissions = $permissions[$moduleSlug] ?? [];

        // Si el array está vacío, se asume que aún no se han restringido pestañas.
        if (! is_array($modulePermissions) || empty($modulePermissions)) {
            return $next($request);
        }

        if (! in_array($permissionKey, $modulePermissions, true)) {
            return response()->json([
                'message' => 'No tienes permiso para acceder a esta sección.',
                'module' => $moduleSlug,
                'permission' => $permissionKey,
            ], 403);
        }

        return $next($request);
    }
}

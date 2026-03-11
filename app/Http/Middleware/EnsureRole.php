<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role)
    {
        $u = $request->user();
        if (!$u) return response()->json(['message'=>'No autenticado'], 401);

        if ($u->role !== $role) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        return $next($request);
    }
}

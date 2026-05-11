<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $u = $request->user();
        if (!$u) return response()->json(['message'=>'No autenticado'], 401);

        if (!in_array($u->role, $roles)) {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        return $next($request);
    }
}

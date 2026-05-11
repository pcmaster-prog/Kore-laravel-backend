<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenant
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (!$u || !$u->empresa_id) {
            return response()->json(['message'=>'Tenant inválido'], 403);
        }
        return $next($request);
    }
}

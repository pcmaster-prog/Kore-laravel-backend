<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || $request->user()->role !== 'aspirante') {
            return response()->json(['message' => 'Acceso denegado. Solo aspirantes.'], 403);
        }

        return $next($request);
    }
}

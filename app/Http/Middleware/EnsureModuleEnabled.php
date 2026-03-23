<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\EmpresaModulo; // ← el modelo correcto

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $moduleKey)
    {
        $empresaId = $request->user()?->empresa_id;
        if (!$empresaId) {
            return response()->json(['message' => 'Tenant inválido'], 403);
        }

        $flag = EmpresaModulo::where('empresa_id', $empresaId)
            ->where('module_slug', $moduleKey)
            ->first();

        if (!$flag || !$flag->enabled) {
            return response()->json(['message' => "Módulo deshabilitado: $moduleKey"], 403);
        }

        return $next($request);
    }
}

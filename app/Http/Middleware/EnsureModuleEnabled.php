<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Modulo;
use App\Models\EmpresaModulo;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $moduleKey)
    {
        $empresaId = $request->user()?->empresa_id;
        if (!$empresaId) {
            return response()->json(['message'=>'Tenant inválido'], 403);
        }

        $mod = Modulo::where('key', $moduleKey)->first();
        if (!$mod) {
            return response()->json(['message'=>'Módulo no existe'], 404);
        }

        $flag = EmpresaModulo::where('empresa_id', $empresaId)
            ->where('modulo_id', $mod->id)
            ->first();

        if (!$flag || !$flag->enabled) {
            return response()->json(['message'=>"Módulo deshabilitado: $moduleKey"], 403);
        }

        return $next($request);
    }
}

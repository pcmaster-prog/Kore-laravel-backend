<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BitacoraController extends Controller
{
    // GET /v1/bitacora/criterios
    public function getCriterios(Request $request)
    {
        $u = $request->user();
        if (!in_array($u->role, ['admin', 'supervisor'])) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $criterios = DB::table('bitacora_criterios')
            ->where('activo', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['items' => $criterios]);
    }

    // POST /v1/bitacora/criterios
    public function saveCriterios(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'criterios'             => ['required', 'array', 'min:1'],
            'criterios.*.label'     => ['required', 'string', 'max:120'],
            'criterios.*.tipo'      => ['required', Rule::in(['positivo', 'negativo'])],
            'criterios.*.sort_order'=> ['nullable', 'integer', 'min:0'],
        ]);

        DB::table('bitacora_criterios')->delete();

        $now = now();
        $rows = array_map(function ($item, $index) use ($now) {
            return [
                'label'      => $item['label'],
                'tipo'       => $item['tipo'],
                'activo'     => true,
                'sort_order' => $item['sort_order'] ?? $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $data['criterios'], array_keys($data['criterios']));

        DB::table('bitacora_criterios')->insert($rows);

        $criterios = DB::table('bitacora_criterios')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['items' => $criterios]);
    }
}

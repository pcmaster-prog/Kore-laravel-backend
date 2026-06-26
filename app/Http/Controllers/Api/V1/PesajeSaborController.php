<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PesajeSabor;
use Illuminate\Http\Request;

class PesajeSaborController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PesajeSabor::query()
            ->where(function ($q) use ($user) {
                $q->where('empresa_id', $user->empresa_id)
                    ->orWhereNull('empresa_id');
            })
            ->orderBy('nombre');

        if ($request->boolean('con_inactivos')) {
            // no filtra activos
        } else {
            $query->where('activo', true);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'presentacion' => 'nullable|string|max:255',
            'peso_estandar' => 'required|numeric|min:0.001',
            'unidad' => 'required|string|max:50',
        ]);

        $data['empresa_id'] = $user->empresa_id;

        $item = PesajeSabor::create($data);

        return response()->json(['message' => 'Sabor creado', 'data' => $item], 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $sabor = PesajeSabor::where('id', $id)
            ->where(function ($q) use ($user) {
                $q->where('empresa_id', $user->empresa_id)
                    ->orWhereNull('empresa_id');
            })
            ->firstOrFail();

        return response()->json(['data' => $sabor]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();

        $sabor = PesajeSabor::where('id', $id)
            ->where(function ($q) use ($user) {
                $q->where('empresa_id', $user->empresa_id)
                    ->orWhereNull('empresa_id');
            })
            ->firstOrFail();

        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'presentacion' => 'nullable|string|max:255',
            'peso_estandar' => 'sometimes|required|numeric|min:0.001',
            'unidad' => 'sometimes|required|string|max:50',
            'activo' => 'sometimes|required|boolean',
        ]);

        $sabor->update($data);

        return response()->json(['message' => 'Sabor actualizado', 'data' => $sabor]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $sabor = PesajeSabor::where('id', $id)
            ->where(function ($q) use ($user) {
                $q->where('empresa_id', $user->empresa_id)
                    ->orWhereNull('empresa_id');
            })
            ->firstOrFail();

        $sabor->delete();

        return response()->json(['message' => 'Sabor eliminado']);
    }
}

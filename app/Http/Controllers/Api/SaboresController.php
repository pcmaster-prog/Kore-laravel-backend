<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PesajeSabor;
use Illuminate\Http\Request;

class SaboresController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PesajeSabor::orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'presentacion' => 'nullable|string|max:255',
        ]);

        $item = PesajeSabor::create($data);

        return response()->json(['message' => 'Sabor creado', 'data' => $item], 201);
    }

    public function update(Request $request, $id)
    {
        $sabor = PesajeSabor::findOrFail($id);

        $data = $request->validate([
            'activo' => 'required|boolean',
        ]);

        $sabor->update($data);

        return response()->json(['message' => 'Sabor actualizado', 'data' => $sabor]);
    }
}

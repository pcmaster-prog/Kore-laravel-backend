<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PesajeRegistro;
use App\Models\PesajeSabor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PesajeRegistroController extends Controller
{
    private function scopeEmpresa($query, $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('empresa_id', $user->empresa_id)
                ->orWhereNull('empresa_id');
        });
    }

    private function splitNombreEmpleado($empleado)
    {
        if (! $empleado) {
            return;
        }

        $parts = explode(' ', $empleado->full_name);
        $empleado->nombres = $parts[0] ?? '';
        $empleado->apellidos = implode(' ', array_slice($parts, 1));
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = PesajeRegistro::with(['empleado', 'sabor'])
            ->orderBy('fecha_registro', 'desc')
            ->orderBy('created_at', 'desc');

        $this->scopeEmpresa($query, $user);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('empleado', function ($sq) use ($search) {
                    $sq->where('full_name', 'ilike', "%{$search}%");
                })
                    ->orWhereHas('sabor', function ($sq) use ($search) {
                        $sq->where('nombre', 'ilike', "%{$search}%")
                            ->orWhere('presentacion', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($request->has('limit')) {
            $query->limit($request->integer('limit'));
        }

        $registros = $query->get();

        $registros->each(function ($registro) {
            $this->splitNombreEmpleado($registro->empleado);
        });

        return response()->json([
            'data' => $registros,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'empleado_id' => 'required|exists:empleados,id',
            'sabor_id' => 'required|exists:pesaje_sabors,id',
            'cantidad' => 'required|numeric|min:0.001',
            'peso' => 'nullable|numeric|min:0.001',
        ]);

        $sabor = PesajeSabor::findOrFail($data['sabor_id']);

        // Si se envía peso manual, usarlo; de lo contrario calcular por cantidad
        if (isset($data['peso'])) {
            $data['peso'] = round(floatval($data['peso']), 2);
        } else {
            $data['peso'] = round(floatval($data['cantidad']) * floatval($sabor->peso_estandar), 2);
        }

        $data['empresa_id'] = $user->empresa_id;
        $data['fecha_registro'] = now();

        $item = PesajeRegistro::create($data);

        // Load relationships
        $item->load(['empleado', 'sabor']);
        $this->splitNombreEmpleado($item->empleado);

        return response()->json(['message' => 'Pesaje registrado', 'data' => $item], 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $registro = PesajeRegistro::with(['empleado', 'sabor'])
            ->where('id', $id);

        $this->scopeEmpresa($registro, $user);

        $registro = $registro->firstOrFail();

        $this->splitNombreEmpleado($registro->empleado);

        return response()->json(['data' => $registro]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();

        $registro = PesajeRegistro::with(['empleado', 'sabor'])
            ->where('id', $id);

        $this->scopeEmpresa($registro, $user);

        $registro = $registro->firstOrFail();

        $data = $request->validate([
            'empleado_id' => 'sometimes|required|exists:empleados,id',
            'sabor_id' => 'sometimes|required|exists:pesaje_sabors,id',
            'cantidad' => 'sometimes|required|numeric|min:0.001',
            'peso' => 'nullable|numeric|min:0.001',
        ]);

        $saborId = $data['sabor_id'] ?? $registro->sabor_id;
        $sabor = PesajeSabor::findOrFail($saborId);

        if (array_key_exists('peso', $data)) {
            if ($data['peso'] !== null) {
                $data['peso'] = round(floatval($data['peso']), 2);
            } else {
                $cantidad = $data['cantidad'] ?? $registro->cantidad;
                $data['peso'] = round(floatval($cantidad) * floatval($sabor->peso_estandar), 2);
            }
        } elseif (array_key_exists('cantidad', $data)) {
            $data['peso'] = round(floatval($data['cantidad']) * floatval($sabor->peso_estandar), 2);
        }

        $registro->update($data);

        $registro->load(['empleado', 'sabor']);
        $this->splitNombreEmpleado($registro->empleado);

        return response()->json(['message' => 'Registro actualizado', 'data' => $registro]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $registro = PesajeRegistro::where('id', $id);

        $this->scopeEmpresa($registro, $user);

        $registro = $registro->firstOrFail();
        $registro->delete();

        return response()->json(['message' => 'Registro de pesaje eliminado']);
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $hoy = Carbon::today();

        $registrosHoy = PesajeRegistro::whereDate('fecha_registro', $hoy);
        $this->scopeEmpresa($registrosHoy, $user);
        $registrosHoy = $registrosHoy->get();

        $kgHoy = $registrosHoy->sum('peso');
        $viajesHoy = $registrosHoy->count();
        $unidadesHoy = $registrosHoy->sum('cantidad');

        // Stats from yesterday for comparison
        $ayer = Carbon::yesterday();
        $registrosAyer = PesajeRegistro::whereDate('fecha_registro', $ayer);
        $this->scopeEmpresa($registrosAyer, $user);
        $registrosAyer = $registrosAyer->get();
        $kgAyer = $registrosAyer->sum('peso');

        $trend = $kgAyer > 0 ? (($kgHoy - $kgAyer) / $kgAyer) * 100 : ($kgHoy > 0 ? 100 : 0);

        $ultimosViajes = PesajeRegistro::with(['empleado', 'sabor'])
            ->orderBy('fecha_registro', 'desc')
            ->orderBy('created_at', 'desc')
            ->take(5);

        $this->scopeEmpresa($ultimosViajes, $user);
        $ultimosViajes = $ultimosViajes->get();

        $ultimosViajes->each(function ($registro) {
            $this->splitNombreEmpleado($registro->empleado);
        });

        return response()->json([
            'data' => [
                'kgIngresadosHoy' => round($kgHoy, 2),
                'viajesHoy' => $viajesHoy,
                'unidadesHoy' => round($unidadesHoy, 2),
                'tendencia' => round($trend, 1),
                'ultimosViajes' => $ultimosViajes,
            ],
        ]);
    }
}

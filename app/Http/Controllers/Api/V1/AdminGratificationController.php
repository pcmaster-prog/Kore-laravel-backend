<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\NumeroALetras;
use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\GratificationReceipt;
use App\Models\GratificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminGratificationController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/tipos-gratificacion
    // ─────────────────────────────────────────────────────────────────────────
    public function indexTipos(Request $request)
    {
        Gate::authorize('manage-payroll');
        $empresaId = $request->user()->empresa_id;

        $tipos = GratificationType::where('empresa_id', $empresaId)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $tipos]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/tipos-gratificacion
    // ─────────────────────────────────────────────────────────────────────────
    public function storeTipo(Request $request)
    {
        Gate::authorize('manage-payroll');
        $empresaId = $request->user()->empresa_id;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'unique:gratification_types,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'frequency' => ['required', 'in:annual,biannual,quarterly,monthly,one_time'],
            'calculation_rules' => ['nullable', 'array'],
        ]);

        $tipo = GratificationType::create([
            'empresa_id' => $empresaId,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'frequency' => $data['frequency'],
            'is_active' => true,
            'calculation_rules' => $data['calculation_rules'] ?? null,
        ]);

        return response()->json(['data' => $tipo], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /admin/tipos-gratificacion/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function updateTipo(Request $request, int $id)
    {
        Gate::authorize('manage-payroll');
        $empresaId = $request->user()->empresa_id;

        $tipo = GratificationType::where('empresa_id', $empresaId)->findOrFail($id);

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:10', 'unique:gratification_types,code,' . $tipo->id],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'frequency' => ['sometimes', 'in:annual,biannual,quarterly,monthly,one_time'],
            'is_active' => ['sometimes', 'boolean'],
            'calculation_rules' => ['nullable', 'array'],
        ]);

        $tipo->update($data);

        return response()->json(['data' => $tipo]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /admin/tipos-gratificacion/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function destroyTipo(Request $request, int $id)
    {
        Gate::authorize('manage-payroll');
        $empresaId = $request->user()->empresa_id;

        $tipo = GratificationType::where('empresa_id', $empresaId)->findOrFail($id);

        if ($tipo->receipts()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar el tipo porque tiene recibos asociados',
            ], 409);
        }

        $tipo->delete();

        return response()->json(['message' => 'Tipo eliminado']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/gratificaciones
    // ─────────────────────────────────────────────────────────────────────────
    public function indexGratificaciones(Request $request)
    {
        Gate::authorize('manage-payroll');
        $empresaId = $request->user()->empresa_id;

        $query = GratificationReceipt::query()
            ->whereHas('gratificationType', fn ($q) => $q->where('empresa_id', $empresaId));

        if ($request->filled('gratification_type_id')) {
            $query->where('gratification_type_id', $request->input('gratification_type_id'));
        }
        if ($request->filled('fiscal_year')) {
            $query->where('fiscal_year', $request->input('fiscal_year'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('empleado_id')) {
            $query->where('empleado_id', $request->input('empleado_id'));
        }

        $receipts = $query->with(['gratificationType', 'empleado', 'signature'])
            ->orderByDesc('issue_date')
            ->paginate(20);

        return response()->json($receipts);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/gratificaciones/generar
    // ─────────────────────────────────────────────────────────────────────────
    public function generar(Request $request)
    {
        Gate::authorize('manage-payroll');
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $data = $request->validate([
            'gratification_type_id' => ['required', 'integer', 'exists:gratification_types,id'],
            'fiscal_year' => ['required', 'string', 'size:4'],
            'employee_ids' => ['required'],
            'issue_date' => ['required', 'date'],
            'payment_date' => ['nullable', 'date'],
            'amounts' => ['required', 'array'],
            'concept_description' => ['nullable', 'string', 'max:2000'],
        ]);

        $tipo = GratificationType::where('empresa_id', $empresaId)
            ->where('id', $data['gratification_type_id'])
            ->firstOrFail();

        $employeeIds = $data['employee_ids'];
        if ($employeeIds === 'all') {
            $employeeIds = Empleado::where('empresa_id', $empresaId)
                ->where('status', 'active')
                ->pluck('id')
                ->toArray();
        } else {
            $employeeIds = (array) $employeeIds;
        }

        $receipts = [];

        DB::transaction(function () use ($empresaId, $tipo, $data, $employeeIds, $u, &$receipts) {
            foreach ($employeeIds as $empId) {
                $emp = Empleado::where('empresa_id', $empresaId)->where('id', $empId)->first();
                if (!$emp) continue;

                $amountData = $data['amounts'][$empId] ?? null;
                if (!$amountData) continue;

                $breakdown = [];
                $totalGratification = 0;

                foreach ($amountData as $key => $value) {
                    if (is_numeric($value) && !in_array($key, ['retentions'])) {
                        $breakdown[] = [
                            'concept' => ucfirst(str_replace('_', ' ', $key)),
                            'amount' => (float) $value,
                        ];
                        $totalGratification += (float) $value;
                    }
                }

                // Si viene retentions en el amountData como array separado
                $retentions = $amountData['retentions'] ?? [];
                $totalRetentions = 0;
                $retentionsList = [];

                if (is_array($retentions)) {
                    foreach ($retentions as $ret) {
                        $retentionsList[] = $ret;
                        $totalRetentions += (float) ($ret['amount'] ?? 0);
                    }
                }

                $netAmount = $totalGratification - $totalRetentions;

                $receipt = GratificationReceipt::create([
                    'gratification_type_id' => $tipo->id,
                    'empleado_id' => $emp->id,
                    'user_id' => $emp->user_id ?? $u->id,
                    'folio' => null, // se genera en boot
                    'status' => 'draft',
                    'fiscal_year' => $data['fiscal_year'],
                    'issue_date' => $data['issue_date'],
                    'payment_date' => $data['payment_date'] ?? null,
                    'employee_name' => $emp->full_name,
                    'position_title' => $emp->position_title,
                    'nss' => $emp->nss,
                    'rfc' => $emp->rfc,
                    'curp' => $emp->curp,
                    'payment_breakdown' => $breakdown,
                    'total_gratification' => $totalGratification,
                    'retentions' => $retentionsList,
                    'total_retentions' => $totalRetentions,
                    'net_amount' => $netAmount,
                    'net_amount_words' => NumeroALetras::convertir($netAmount),
                    'concept_description' => $data['concept_description'] ?? null,
                    'generated_at' => now(),
                ]);

                $receipts[] = $receipt;
            }
        });

        return response()->json([
            'message' => 'Recibos generados correctamente',
            'count' => count($receipts),
            'data' => $receipts,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/gratificaciones/{id}/aprobar
    // ─────────────────────────────────────────────────────────────────────────
    public function aprobar(Request $request, int $id)
    {
        Gate::authorize('manage-payroll');
        $u = $request->user();
        $empresaId = $u->empresa_id;

        $receipt = GratificationReceipt::where('id', $id)
            ->whereHas('gratificationType', fn ($q) => $q->where('empresa_id', $empresaId))
            ->firstOrFail();

        if ($receipt->status !== 'draft') {
            return response()->json(['message' => 'Este recibo no puede ser aprobado'], 409);
        }

        $receipt->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $u->id,
        ]);

        return response()->json([
            'message' => 'Recibo aprobado',
            'data' => $receipt->fresh(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\GratificationReceipt;
use App\Models\PayrollReceipt;
use App\Models\ReceiptSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeReceiptController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /mis-recibos/nomina
    // ─────────────────────────────────────────────────────────────────────────
    public function indexNomina(Request $request)
    {
        $emp = $this->getEmpleado($request);
        if (! $emp) {
            return response()->json(['data' => [], 'pending_count' => 0, 'signed_count' => 0]);
        }

        $receipts = PayrollReceipt::where('empleado_id', $emp->id)
            ->whereIn('status', ['pending', 'signed'])
            ->orderByDesc('period_start')
            ->with('signature')
            ->get();

        $pendingCount = $receipts->where('status', 'pending')->count();
        $signedCount = $receipts->where('status', 'signed')->count();

        return response()->json([
            'data' => $receipts->map(fn ($r) => [
                'id' => $r->id,
                'folio' => $r->folio,
                'status' => $r->status,
                'period_start' => $r->period_start->toDateString(),
                'period_end' => $r->period_end->toDateString(),
                'payment_date' => $r->payment_date?->toDateString(),
                'net_pay' => $r->net_pay,
                'total_perceptions' => $r->total_perceptions,
                'total_deductions' => $r->total_deductions,
                'days_worked' => $r->days_worked,
                'position_title' => $r->position_title,
                'signed_at' => $r->signature?->signed_at,
                'can_sign' => $r->status === 'pending',
            ]),
            'pending_count' => $pendingCount,
            'signed_count' => $signedCount,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /mis-recibos/nomina/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function showNomina(Request $request, int $id)
    {
        $emp = $this->getEmpleado($request);
        if (! $emp) {
            return response()->json(['message' => 'No tienes recibos disponibles'], 403);
        }

        $receipt = PayrollReceipt::where('id', $id)
            ->where('empleado_id', $emp->id)
            ->with('signature')
            ->firstOrFail();

        return response()->json([
            'id' => $receipt->id,
            'folio' => $receipt->folio,
            'status' => $receipt->status,
            'period_start' => $receipt->period_start->toDateString(),
            'period_end' => $receipt->period_end->toDateString(),
            'payment_date' => $receipt->payment_date?->toDateString(),
            'employee_name' => $receipt->employee_name,
            'position_title' => $receipt->position_title,
            'nss' => $receipt->nss,
            'rfc' => $receipt->rfc,
            'curp' => $receipt->curp,
            'daily_salary' => $receipt->daily_salary,
            'sbc' => $receipt->sbc,
            'days_worked' => $receipt->days_worked,
            'perceptions' => $receipt->perceptions,
            'total_perceptions' => $receipt->total_perceptions,
            'deductions' => $receipt->deductions,
            'total_deductions' => $receipt->total_deductions,
            'net_pay' => $receipt->net_pay,
            'net_pay_words' => $receipt->net_pay_words,
            'payment_method' => $receipt->payment_method,
            'bank_account' => $receipt->bank_account,
            'clabe' => $receipt->clabe,
            'generated_at' => $receipt->generated_at,
            'approved_at' => $receipt->approved_at,
            'signature' => [
                'signed_at' => $receipt->signature?->signed_at,
                'password_verified' => $receipt->signature?->password_verified ?? false,
                'document_hash' => $receipt->signature?->document_hash,
            ],
            'can_sign' => $receipt->status === 'pending',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /mis-recibos/nomina/{id}/firmar
    // ─────────────────────────────────────────────────────────────────────────
    public function firmarNomina(Request $request, int $id)
    {
        $emp = $this->getEmpleado($request);
        if (! $emp) {
            return response()->json(['message' => 'El recibo no pertenece a este empleado'], 403);
        }

        $data = $request->validate([
            'signature_image' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $receipt = PayrollReceipt::where('id', $id)
            ->where('empleado_id', $emp->id)
            ->first();

        if (! $receipt) {
            return response()->json(['message' => 'El recibo no pertenece a este empleado'], 403);
        }

        if ($receipt->status !== 'pending') {
            return response()->json(['message' => 'Este recibo ya fue firmado'], 409);
        }

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Contraseña incorrecta']);
        }

        $signaturePath = $this->storeSignatureImage($data['signature_image']);

        $hashData = $receipt->folio.$receipt->net_pay.$receipt->total_perceptions.$receipt->total_deductions.$receipt->empleado_id.$receipt->period_start->toDateString();
        $documentHash = hash('sha256', $hashData);

        $signature = ReceiptSignature::create([
            'receivable_type' => PayrollReceipt::class,
            'receivable_id' => $receipt->id,
            'empleado_id' => $emp->id,
            'user_id' => $request->user()->id,
            'signature_image_path' => $signaturePath,
            'password_verified' => true,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'document_hash' => $documentHash,
            'signed_at' => now(),
        ]);

        $receipt->update(['status' => 'signed']);

        return response()->json([
            'success' => true,
            'message' => 'Recibo firmado correctamente',
            'signature' => [
                'signed_at' => $signature->signed_at,
                'document_hash' => $signature->document_hash,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /mis-recibos/gratificaciones
    // ─────────────────────────────────────────────────────────────────────────
    public function indexGratificaciones(Request $request)
    {
        $emp = $this->getEmpleado($request);
        if (! $emp) {
            return response()->json(['data' => []]);
        }

        $receipts = GratificationReceipt::where('empleado_id', $emp->id)
            ->whereIn('status', ['approved', 'signed'])
            ->orderByDesc('issue_date')
            ->with(['gratificationType', 'signature'])
            ->get();

        return response()->json([
            'data' => $receipts->map(fn ($r) => [
                'id' => $r->id,
                'folio' => $r->folio,
                'status' => $r->status,
                'gratification_type' => $r->gratificationType ? [
                    'id' => $r->gratificationType->id,
                    'code' => $r->gratificationType->code,
                    'name' => $r->gratificationType->name,
                ] : null,
                'fiscal_year' => $r->fiscal_year,
                'issue_date' => $r->issue_date->toDateString(),
                'net_amount' => $r->net_amount,
                'total_gratification' => $r->total_gratification,
                'total_retentions' => $r->total_retentions,
                'signed_at' => $r->signature?->signed_at,
                'can_sign' => $r->status === 'approved',
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /mis-recibos/gratificaciones/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function showGratificacion(Request $request, int $id)
    {
        $emp = $this->getEmpleado($request);
        if (! $emp) {
            return response()->json(['message' => 'No tienes recibos disponibles'], 403);
        }

        $receipt = GratificationReceipt::where('id', $id)
            ->where('empleado_id', $emp->id)
            ->with(['gratificationType', 'signature'])
            ->firstOrFail();

        return response()->json([
            'id' => $receipt->id,
            'folio' => $receipt->folio,
            'gratification_type' => $receipt->gratificationType ? [
                'id' => $receipt->gratificationType->id,
                'code' => $receipt->gratificationType->code,
                'name' => $receipt->gratificationType->name,
            ] : null,
            'fiscal_year' => $receipt->fiscal_year,
            'issue_date' => $receipt->issue_date->toDateString(),
            'payment_date' => $receipt->payment_date?->toDateString(),
            'concept_description' => $receipt->concept_description,
            'employee_name' => $receipt->employee_name,
            'position_title' => $receipt->position_title,
            'nss' => $receipt->nss,
            'rfc' => $receipt->rfc,
            'curp' => $receipt->curp,
            'payment_breakdown' => $receipt->payment_breakdown,
            'total_gratification' => $receipt->total_gratification,
            'retentions' => $receipt->retentions,
            'total_retentions' => $receipt->total_retentions,
            'net_amount' => $receipt->net_amount,
            'net_amount_words' => $receipt->net_amount_words,
            'approved_at' => $receipt->approved_at,
            'signature' => [
                'signed_at' => $receipt->signature?->signed_at,
                'password_verified' => $receipt->signature?->password_verified ?? false,
                'document_hash' => $receipt->signature?->document_hash,
            ],
            'can_sign' => $receipt->status === 'approved',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /mis-recibos/gratificaciones/{id}/firmar
    // ─────────────────────────────────────────────────────────────────────────
    public function firmarGratificacion(Request $request, int $id)
    {
        $emp = $this->getEmpleado($request);
        if (! $emp) {
            return response()->json(['message' => 'El recibo no pertenece a este empleado'], 403);
        }

        $data = $request->validate([
            'signature_image' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $receipt = GratificationReceipt::where('id', $id)
            ->where('empleado_id', $emp->id)
            ->first();

        if (! $receipt) {
            return response()->json(['message' => 'El recibo no pertenece a este empleado'], 403);
        }

        if ($receipt->status !== 'approved') {
            return response()->json(['message' => 'Este recibo ya fue firmado'], 409);
        }

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Contraseña incorrecta']);
        }

        $signaturePath = $this->storeSignatureImage($data['signature_image']);

        $hashData = $receipt->folio.$receipt->net_amount.$receipt->total_gratification.$receipt->total_retentions.$receipt->empleado_id.$receipt->issue_date->toDateString();
        $documentHash = hash('sha256', $hashData);

        $signature = ReceiptSignature::create([
            'receivable_type' => GratificationReceipt::class,
            'receivable_id' => $receipt->id,
            'empleado_id' => $emp->id,
            'user_id' => $request->user()->id,
            'signature_image_path' => $signaturePath,
            'password_verified' => true,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'document_hash' => $documentHash,
            'signed_at' => now(),
        ]);

        $receipt->update(['status' => 'signed']);

        return response()->json([
            'success' => true,
            'message' => 'Recibo firmado correctamente',
            'signature' => [
                'signed_at' => $signature->signed_at,
                'document_hash' => $signature->document_hash,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function getEmpleado(Request $request): ?Empleado
    {
        return Empleado::where('user_id', $request->user()->id)->first();
    }

    private function storeSignatureImage(string $base64Image): string
    {
        $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $base64Image);
        $imageData = base64_decode($base64);

        if (! $imageData) {
            throw ValidationException::withMessages(['signature_image' => 'La imagen de firma no es válida']);
        }

        $filename = 'sig_'.uniqid().'.png';
        $path = 'signatures/'.now()->format('Y/m').'/'.$filename;
        Storage::disk('local')->put($path, $imageData);

        return $path;
    }
}

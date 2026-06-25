<?php

namespace Tests\Feature;

use App\Helpers\NumeroALetras;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\PayrollPeriod;
use App\Models\PayrollReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiptSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_sign_payroll_receipt(): void
    {
        $empresa = Empresa::create([
            'id' => Str::uuid(),
            'name' => 'Test Corp',
            'slug' => 'test-corp',
        ]);

        DB::table('empresa_modules')->insert([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'module_slug' => 'nomina',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name' => 'Juan Pérez',
            'email' => 'juan@test.com',
            'password' => Hash::make('password123'),
            'role' => 'empleado',
            'is_active' => true,
        ]);

        $emp = Empleado::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'user_id' => $user->id,
            'full_name' => 'Juan Pérez',
            'status' => 'active',
        ]);

        $period = PayrollPeriod::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'week_start' => now()->startOfWeek(),
            'week_end' => now()->endOfWeek(),
            'status' => 'approved',
        ]);

        $receipt = PayrollReceipt::create([
            'payroll_period_id' => $period->id,
            'empleado_id' => $emp->id,
            'user_id' => $user->id,
            'period_start' => $period->week_start,
            'period_end' => $period->week_end,
            'employee_name' => $emp->full_name,
            'net_pay' => 5000.00,
            'total_perceptions' => 6000.00,
            'total_deductions' => 1000.00,
            'generated_at' => now(),
        ]);

        $this->assertNotEmpty($receipt->folio);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/mis-recibos/nomina/{$receipt->id}/firmar", [
                'signature_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                'password' => 'password123',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $receipt->refresh();
        $this->assertEquals('signed', $receipt->status);
        $this->assertNotNull($receipt->signature);
        $this->assertNotNull($receipt->signature->document_hash);
    }

    public function test_signature_fails_with_wrong_password(): void
    {
        $empresa = Empresa::create([
            'id' => Str::uuid(),
            'name' => 'Test Corp',
            'slug' => 'test-corp',
        ]);

        DB::table('empresa_modules')->insert([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'module_slug' => 'nomina',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name' => 'Juan Pérez',
            'email' => 'juan@test.com',
            'password' => Hash::make('password123'),
            'role' => 'empleado',
            'is_active' => true,
        ]);

        $emp = Empleado::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'user_id' => $user->id,
            'full_name' => 'Juan Pérez',
            'status' => 'active',
        ]);

        $period = PayrollPeriod::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'week_start' => now()->startOfWeek(),
            'week_end' => now()->endOfWeek(),
            'status' => 'approved',
        ]);

        $receipt = PayrollReceipt::create([
            'payroll_period_id' => $period->id,
            'empleado_id' => $emp->id,
            'user_id' => $user->id,
            'period_start' => $period->week_start,
            'period_end' => $period->week_end,
            'employee_name' => $emp->full_name,
            'net_pay' => 5000.00,
            'total_perceptions' => 6000.00,
            'total_deductions' => 1000.00,
            'generated_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/mis-recibos/nomina/{$receipt->id}/firmar", [
                'signature_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                'password' => 'wrongpassword',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_numero_a_letras_helper(): void
    {
        $this->assertStringContainsString('Seis mil trescientos sesenta y cinco', NumeroALetras::convertir(6365.00));
        $this->assertStringContainsString('00/100', NumeroALetras::convertir(6365.00));
    }
}

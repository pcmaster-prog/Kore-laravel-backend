<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\LateArrivalRequest;
use App\Models\TardinessConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LateArrivalRequestTest extends TestCase
{
    use RefreshDatabase;

    private function setupEmpresaYAdmin(): array
    {
        $empresa = Empresa::create([
            'id'   => Str::uuid(),
            'name' => 'Test Corp',
            'slug' => 'test-corp',
            'settings' => [
                'operativo' => [
                    'check_in_time' => '09:00',
                    'check_out_time' => '18:00',
                    'max_hours' => 8,
                    'meal_duration_minutes' => 30,
                    'break_duration_minutes' => 10,
                    'break_pauses_clock' => true,
                ],
            ],
        ]);

        \Illuminate\Support\Facades\DB::table('empresa_modules')->insert([
            'id'          => Str::uuid(),
            'empresa_id'  => $empresa->id,
            'module_slug' => 'asistencia',
            'enabled'     => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $admin = User::create([
            'id'        => Str::uuid(),
            'empresa_id'=> $empresa->id,
            'name'      => 'Admin Test',
            'email'     => 'admin@test.com',
            'password'  => Hash::make('password123'),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        return [$empresa, $admin];
    }

    private function crearEmpleado($empresa, string $nombre, string $email, ?string $checkInTime = null): array
    {
        $user = User::create([
            'id'        => Str::uuid(),
            'empresa_id'=> $empresa->id,
            'name'      => $nombre,
            'email'     => $email,
            'password'  => Hash::make('password123'),
            'role'      => 'empleado',
            'is_active' => true,
        ]);

        $emp = Empleado::create([
            'id'        => Str::uuid(),
            'empresa_id'=> $empresa->id,
            'user_id'   => $user->id,
            'full_name' => $nombre,
            'status'    => 'active',
            'check_in_time' => $checkInTime,
        ]);

        return [$user, $emp];
    }

    private function setupTardinessConfig($empresaId): void
    {
        TardinessConfig::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresaId,
            'grace_period_minutes' => 10,
            'late_threshold_minutes' => 1,
            'lates_to_absence' => 3,
            'accumulation_period' => 'month',
            'penalize_rest_day' => true,
            'notify_employee_on_late' => true,
            'notify_manager_on_late' => true,
        ]);
    }

    public function test_check_in_se_bloquea_cuando_llega_tarde_sin_oportunidad(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [$user, $emp] = $this->crearEmpleado($empresa, 'Adán Tarde', 'adan@test.com', '09:00');
        $this->setupTardinessConfig($empresa->id);

        // Hora actual: 09:16 (después de 09:00 + 10 min gracia + 1 min umbral)
        $this->travelTo(now()->setTime(9, 16));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/asistencia/entrada');

        $response->assertStatus(409)
            ->assertJsonPath('code', 'CHECK_IN_LATE_BLOCKED')
            ->assertJsonPath('scheduled_time', '09:00');

        $this->assertDatabaseMissing('attendance_days', [
            'empresa_id' => $empresa->id,
            'empleado_id' => $emp->id,
            'date' => now()->toDateString(),
        ]);
    }

    public function test_check_in_permitido_con_oportunidad_aprobada(): void
    {
        $this->travelTo(now()->setTime(9, 16));

        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [$user, $emp] = $this->crearEmpleado($empresa, 'Adán Oportunidad', 'adan2@test.com', '09:00');
        $this->setupTardinessConfig($empresa->id);

        $request = LateArrivalRequest::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'empleado_id' => $emp->id,
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'motivo' => 'Tráfico',
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $this->travelTo(now()->setTime(9, 16));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/asistencia/entrada');

        $response->assertOk()
            ->assertJsonPath('is_late', true)
            ->assertJsonPath('late_minutes', 16);

        $this->assertDatabaseHas('attendance_days', [
            'empresa_id' => $empresa->id,
            'empleado_id' => $emp->id,
            'late_minutes' => 16,
        ]);
    }

    public function test_empleado_puede_solicitar_oportunidad(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [$user, $emp] = $this->crearEmpleado($empresa, 'Adán Solicita', 'adan3@test.com', '09:00');

        $this->travelTo(now()->setTime(9, 16));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/late-arrival-requests', [
                'motivo' => 'Problema de transporte público',
            ]);

        $response->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.empleado_id', $emp->id);

        $this->assertDatabaseHas('late_arrival_requests', [
            'empresa_id' => $empresa->id,
            'empleado_id' => $emp->id,
            'status' => 'pending',
        ]);
    }

    public function test_no_se_permite_dos_solicitudes_pendientes_por_dia(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [$user, $emp] = $this->crearEmpleado($empresa, 'Adán Doble', 'adan4@test.com', '09:00');

        LateArrivalRequest::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'empleado_id' => $emp->id,
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'motivo' => 'Primera',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/late-arrival-requests', [
                'motivo' => 'Segunda',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'LATE_REQUEST_PENDING_EXISTS');
    }

    public function test_admin_puede_aprobar_y_rechazar_solicitudes(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [$user, $emp] = $this->crearEmpleado($empresa, 'Adán Revisa', 'adan5@test.com', '09:00');

        $request = LateArrivalRequest::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'empleado_id' => $emp->id,
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'motivo' => 'Motivo',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/late-arrival-requests/{$request->id}", [
                'status' => 'approved',
                'reviewer_note' => 'Solo por hoy',
            ]);

        $response->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonPath('request.reviewer_note', 'Solo por hoy');

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/late-arrival-requests/{$request->id}", [
                'status' => 'rejected',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'LATE_REQUEST_ALREADY_RESOLVED');
    }

    public function test_mis_hoy_incluye_informacion_de_oportunidad(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [$user, $emp] = $this->crearEmpleado($empresa, 'Adán Hoy', 'adan6@test.com', '08:30');
        $this->setupTardinessConfig($empresa->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/asistencia/mis-hoy');

        $response->assertOk()
            ->assertJsonPath('employee_check_in_time', '08:30')
            ->assertJsonPath('late_window_closes_at', '08:41')
            ->assertJsonPath('has_approved_late_request', false)
            ->assertJsonPath('pending_late_request', null);
    }

    public function test_horario_de_empresa_es_fallback_cuando_empleado_no_tiene_hora(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [$user, $emp] = $this->crearEmpleado($empresa, 'Adán Fallback', 'adan7@test.com', null);
        $this->setupTardinessConfig($empresa->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/asistencia/mis-hoy');

        $response->assertOk()
            ->assertJsonPath('employee_check_in_time', '09:00');
    }
}

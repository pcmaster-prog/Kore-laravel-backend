<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\MealSchedule;
use App\Models\MealScheduleChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MealScheduleChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private function setupEmpresaAdminYEmpleado(): array
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
            'id'         => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name'       => 'Admin',
            'email'      => 'admin@example.com',
            'password'   => Hash::make('password'),
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $employeeUser = User::create([
            'id'         => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name'       => 'Employee',
            'email'      => 'employee@example.com',
            'password'   => Hash::make('password'),
            'role'       => 'employee',
            'is_active'  => true,
        ]);

        $empleado = Empleado::create([
            'id'              => Str::uuid(),
            'empresa_id'      => $empresa->id,
            'user_id'         => $employeeUser->id,
            'full_name'       => 'Empleado Test',
            'position_title'  => 'Operador',
            'payment_type'    => 'weekly',
            'daily_hours'     => 8,
            'check_in_time'   => '09:00',
        ]);

        MealSchedule::create([
            'id'                => Str::uuid(),
            'empresa_id'        => $empresa->id,
            'employee_id'       => $empleado->id,
            'meal_start_time'   => '14:00',
            'duration_minutes'  => 30,
        ]);

        return [$empresa, $admin, $employeeUser, $empleado];
    }

    public function test_empleado_puede_crear_solicitud_de_cambio_de_horario(): void
    {
        list(,, $user, $empleado) = $this->setupEmpresaAdminYEmpleado();

        $token = $user->createToken('test')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/asistencia/meal-schedule-change-requests', [
                'requested_meal_start_time' => '15:00',
                'duration_minutes' => 45,
                'justification' => 'Tengo cita médica',
            ]);

        $res->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_meal_start_time', '15:00')
            ->assertJsonPath('data.duration_minutes', 45)
            ->assertJsonPath('data.justification', 'Tengo cita médica');

        $this->assertDatabaseHas('meal_schedule_change_requests', [
            'empleado_id' => $empleado->id,
            'status' => 'pending',
        ]);
    }

    public function test_admin_puede_aprobar_y_se_actualiza_meal_schedule(): void
    {
        list($empresa, $admin, $user, $empleado) = $this->setupEmpresaAdminYEmpleado();

        $request = MealScheduleChangeRequest::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'empleado_id' => $empleado->id,
            'requested_meal_start_time' => '15:30',
            'duration_minutes' => 60,
            'justification' => 'Motivo',
            'status' => 'pending',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson("/api/v1/asistencia/meal-schedule-change-requests/{$request->id}/review", [
                'status' => 'approved',
                'reviewer_note' => 'Ok',
            ]);

        $res->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('meal_schedules', [
            'employee_id' => $empleado->id,
            'meal_start_time' => '15:30',
            'duration_minutes' => 60,
        ]);
    }

    public function test_admin_puede_listar_solicitudes_pendientes(): void
    {
        list($empresa, $admin, $user, $empleado) = $this->setupEmpresaAdminYEmpleado();

        MealScheduleChangeRequest::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'empleado_id' => $empleado->id,
            'requested_meal_start_time' => '15:30',
            'duration_minutes' => 60,
            'justification' => 'Motivo',
            'status' => 'pending',
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/asistencia/meal-schedule-change-requests?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_empleado_no_puede_revisar_solicitud(): void
    {
        list($empresa,, $user, $empleado) = $this->setupEmpresaAdminYEmpleado();

        $request = MealScheduleChangeRequest::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'empleado_id' => $empleado->id,
            'requested_meal_start_time' => '15:30',
            'duration_minutes' => 60,
            'justification' => 'Motivo',
            'status' => 'pending',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson("/api/v1/asistencia/meal-schedule-change-requests/{$request->id}/review", [
                'status' => 'approved',
            ])
            ->assertForbidden();
    }

    public function test_endpoint_en_comida_muestra_empleados_en_comida(): void
    {
        list($empresa, $admin, $user, $empleado) = $this->setupEmpresaAdminYEmpleado();

        AttendanceDay::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'empleado_id' => $empleado->id,
            'date' => now()->toDateString(),
            'status' => 'open',
            'lunch_start_at' => now()->subMinutes(10),
            'totals' => [],
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/asistencia/en-comida');
        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.empleado_id', $empleado->id)
            ->assertJsonPath('data.0.is_overtime', false)
            ->assertJsonPath('meta.meal_duration_minutes', 30);
    }

    public function test_en_comida_requiere_rol_admin_supervisor_rh(): void
    {
        list(,, $user,) = $this->setupEmpresaAdminYEmpleado();

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/asistencia/en-comida')
            ->assertForbidden();
    }
}

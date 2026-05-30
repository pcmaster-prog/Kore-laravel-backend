<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private function setupEmpresaYAdmin(): array
    {
        $empresa = Empresa::create([
            'id'   => Str::uuid(),
            'name' => 'Test Corp',
            'slug' => 'test-corp',
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

    private function crearEmpleado($empresa, string $nombre, string $email): array
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
        ]);

        return [$user, $emp];
    }

    // ─────────────────────────────────────────────
    // Cierre Masivo
    // ─────────────────────────────────────────────

    public function test_cerrar_masivo_sin_empleados_en_turno_devuelve_422(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencia/cerrar-masivo', [
                'date'   => now()->toDateString(),
                'motivo' => 'Cierre de turno por fin de jornada laboral',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'No hay empleados en turno para cerrar en esta fecha');
    }

    public function test_cerrar_masivo_cierra_jornadas_abiertas(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [, $emp1] = $this->crearEmpleado($empresa, 'Juan Pérez', 'juan@test.com');
        [, $emp2] = $this->crearEmpleado($empresa, 'María García', 'maria@test.com');

        $date = now()->toDateString();

        // Empleado 1: en turno (entrada sin salida)
        AttendanceDay::create([
            'id'                => Str::uuid(),
            'empresa_id'        => $empresa->id,
            'empleado_id'       => $emp1->id,
            'date'              => $date,
            'status'            => 'open',
            'first_check_in_at' => now()->subHours(4),
        ]);

        // Empleado 2: en turno
        AttendanceDay::create([
            'id'                => Str::uuid(),
            'empresa_id'        => $empresa->id,
            'empleado_id'       => $emp2->id,
            'date'              => $date,
            'status'            => 'open',
            'first_check_in_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencia/cerrar-masivo', [
                'date'   => $date,
                'motivo' => 'Cierre de turno por fin de jornada laboral',
            ]);

        $response->assertOk()
            ->assertJsonPath('closed_count', 2)
            ->assertJsonCount(2, 'employees');

        $this->assertDatabaseHas('attendance_days', [
            'empleado_id'       => $emp1->id,
            'status'            => 'closed',
            'admin_closed'      => true,
            'admin_closed_by'   => $admin->id,
        ]);

        $this->assertDatabaseHas('attendance_days', [
            'empleado_id'       => $emp2->id,
            'status'            => 'closed',
            'admin_closed'      => true,
        ]);
    }

    public function test_cerrar_masivo_ignora_descansos_y_festivos(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [, $emp1] = $this->crearEmpleado($empresa, 'Juan Pérez', 'juan@test.com');

        $date = now()->toDateString();

        // Empleado con día de descanso
        AttendanceDay::create([
            'id'          => Str::uuid(),
            'empresa_id'  => $empresa->id,
            'empleado_id' => $emp1->id,
            'date'        => $date,
            'status'      => 'day_off',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencia/cerrar-masivo', [
                'date'   => $date,
                'motivo' => 'Cierre de turno',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'No hay empleados en turno para cerrar en esta fecha');
    }

    public function test_cerrar_masivo_usa_hora_especifica_cuando_se_envia(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [, $emp1] = $this->crearEmpleado($empresa, 'Juan Pérez', 'juan@test.com');

        $date = now()->toDateString();
        $horaCierre = '17:30';

        AttendanceDay::create([
            'id'                => Str::uuid(),
            'empresa_id'        => $empresa->id,
            'empleado_id'       => $emp1->id,
            'date'              => $date,
            'status'            => 'open',
            'first_check_in_at' => now()->subHours(8),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencia/cerrar-masivo', [
                'date'   => $date,
                'time'   => $horaCierre,
                'motivo' => 'Cierre de turno por fin de jornada laboral',
            ]);

        $response->assertOk()
            ->assertJsonPath('closed_count', 1);

        $this->assertDatabaseHas('attendance_days', [
            'empleado_id'        => $emp1->id,
            'status'             => 'closed',
            'admin_closed'       => true,
            'admin_closed_by'    => $admin->id,
            'last_check_out_at'  => $date . ' ' . $horaCierre . ':00',
        ]);
    }

    // ─────────────────────────────────────────────
    // Reporte Semanal
    // ─────────────────────────────────────────────

    public function test_reporte_semanal_rango_invalido_devuelve_422(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reportes/asistencia-semanal?from=2026-05-16&to=2026-05-10');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_reporte_semanal_incluye_todos_los_empleados_activos(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [, $emp1] = $this->crearEmpleado($empresa, 'Juan Pérez', 'juan@test.com');
        [, $emp2] = $this->crearEmpleado($empresa, 'María García', 'maria@test.com');

        $from = now()->startOfWeek(Carbon\Carbon::SUNDAY)->toDateString();
        $to = now()->endOfWeek(Carbon\Carbon::SATURDAY)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reportes/asistencia-semanal?from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonCount(2, 'filas')
            ->assertJsonPath('filas.0.empleado.nombre', 'Juan Pérez')
            ->assertJsonPath('filas.1.empleado.nombre', 'María García');
    }

    public function test_reporte_semanal_filtra_por_empleado_ids(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [, $emp1] = $this->crearEmpleado($empresa, 'Juan Pérez', 'juan@test.com');
        [, $emp2] = $this->crearEmpleado($empresa, 'María García', 'maria@test.com');

        $from = now()->startOfWeek(Carbon\Carbon::SUNDAY)->toDateString();
        $to = now()->endOfWeek(Carbon\Carbon::SATURDAY)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reportes/asistencia-semanal?from={$from}&to={$to}&empleado_ids={$emp1->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'filas')
            ->assertJsonPath('filas.0.empleado.nombre', 'Juan Pérez');
    }

    // ─────────────────────────────────────────────
    // Reporte por Empleado
    // ─────────────────────────────────────────────

    public function test_reporte_empleado_de_otra_empresa_devuelve_404(): void
    {
        [$empresa1, $admin] = $this->setupEmpresaYAdmin();

        $empresa2 = Empresa::create([
            'id'   => Str::uuid(),
            'name' => 'Otra Corp',
            'slug' => 'otra-corp',
        ]);

        [, $empOtro] = $this->crearEmpleado($empresa2, 'Extranjero', 'extranjero@test.com');

        $from = now()->toDateString();
        $to = now()->toDateString();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reportes/empleado/{$empOtro->id}?from={$from}&to={$to}");

        $response->assertNotFound()
            ->assertJsonPath('message', 'Empleado no encontrado');
    }

    public function test_reporte_empleado_devuelve_detalle_correcto(): void
    {
        [$empresa, $admin] = $this->setupEmpresaYAdmin();
        [, $emp] = $this->crearEmpleado($empresa, 'Juan Pérez', 'juan@test.com');

        $date = now()->toDateString();

        AttendanceDay::create([
            'id'                => Str::uuid(),
            'empresa_id'        => $empresa->id,
            'empleado_id'       => $emp->id,
            'date'              => $date,
            'status'            => 'closed',
            'first_check_in_at' => now()->subHours(8),
            'last_check_out_at' => now(),
            'late_minutes'      => 15,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reportes/empleado/{$emp->id}?from={$date}&to={$date}&incluir_retardos=1");

        $response->assertOk()
            ->assertJsonPath('empleado.nombre', 'Juan Pérez')
            ->assertJsonPath('resumen.dias_trabajados', 1)
            ->assertJsonPath('resumen.total_retardos', 1)
            ->assertJsonPath('detalle.0.estado', 'retardo')
            ->assertJsonPath('detalle.0.retardos_minutos', 15);
    }
}

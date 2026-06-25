<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PositionPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create([
            'name' => 'Empresa de prueba',
            'slug' => 'empresa-prueba-'.uniqid(),
            'status' => 'active',
            'plan' => 'starter',
        ]);

        $this->seedEmpresaModule('tareas');
    }

    public function test_position_can_store_permissions(): void
    {
        $admin = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $permissions = [
            'produccion_maderas' => ['ensamblaje'],
            'produccion_pesaje' => ['registrar'],
        ];

        $response = $this->actingAs($admin)->postJson('/api/v1/positions', [
            'name' => 'Ensamblador',
            'description' => 'Puesto de prueba',
            'permissions' => $permissions,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.permisos', $permissions);

        $this->assertDatabaseHas('positions', [
            'name' => 'Ensamblador',
            'permissions' => json_encode($permissions),
        ]);
    }

    public function test_position_update_replaces_permissions(): void
    {
        $admin = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $position = Position::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Ensamblador',
            'permissions' => ['produccion_maderas' => ['ensamblaje']],
        ]);

        $newPermissions = ['produccion_maderas' => ['ensamblaje', 'pedidos']];

        $response = $this->actingAs($admin)->patchJson("/api/v1/positions/{$position->id}", [
            'permissions' => $newPermissions,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.permisos', $newPermissions);

        $this->assertDatabaseHas('positions', [
            'id' => $position->id,
            'permissions' => json_encode($newPermissions),
        ]);
    }

    public function test_me_permissions_returns_position_permissions(): void
    {
        $position = Position::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Ensamblador',
            'permissions' => ['produccion_maderas' => ['ensamblaje']],
        ]);

        $user = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'role' => 'empleado',
            'email_verified_at' => now(),
        ]);

        Empleado::create([
            'empresa_id' => $this->empresa->id,
            'user_id' => $user->id,
            'position_id' => $position->id,
            'full_name' => $user->name,
            'status' => 'active',
            'payment_type' => 'hourly',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me/permisos');

        $response->assertOk()
            ->assertJsonPath('data.produccion_maderas', ['ensamblaje']);
    }

    public function test_admin_me_permissions_returns_all_permissions(): void
    {
        $admin = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/me/permisos');

        $response->assertOk()
            ->assertJsonPath('data.produccion_maderas', ['dashboard', 'inventario', 'produccion', 'ensamblaje', 'pedidos'])
            ->assertJsonPath('data.produccion_pesaje', ['dashboard', 'registrar', 'historial']);
    }

    public function test_employee_without_position_permissions_gets_empty_object(): void
    {
        $user = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'role' => 'empleado',
            'email_verified_at' => now(),
        ]);

        Empleado::create([
            'empresa_id' => $this->empresa->id,
            'user_id' => $user->id,
            'position_id' => null,
            'full_name' => $user->name,
            'status' => 'active',
            'payment_type' => 'hourly',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/me/permisos');

        $response->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_employee_is_blocked_without_specific_permission(): void
    {
        $position = Position::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Ensamblador',
            'permissions' => ['produccion_maderas' => ['ensamblaje']],
        ]);

        $user = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'role' => 'empleado',
            'email_verified_at' => now(),
        ]);

        Empleado::create([
            'empresa_id' => $this->empresa->id,
            'user_id' => $user->id,
            'position_id' => $position->id,
            'full_name' => $user->name,
            'status' => 'active',
            'payment_type' => 'hourly',
        ]);

        $this->seedEmpresaModule('produccion_maderas');

        $response = $this->actingAs($user)->getJson('/api/v1/maderas/inventario');

        $response->assertForbidden()
            ->assertJsonPath('permission', 'inventario');
    }

    public function test_employee_with_specific_permission_can_access(): void
    {
        $position = Position::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Ensamblador',
            'permissions' => ['produccion_maderas' => ['ensamblaje']],
        ]);

        $user = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'role' => 'empleado',
            'email_verified_at' => now(),
        ]);

        Empleado::create([
            'empresa_id' => $this->empresa->id,
            'user_id' => $user->id,
            'position_id' => $position->id,
            'full_name' => $user->name,
            'status' => 'active',
            'payment_type' => 'hourly',
        ]);

        $this->seedEmpresaModule('produccion_maderas');

        // La ruta devuelve 200 o 404 según haya datos, pero no 403.
        $response = $this->actingAs($user)->getJson('/api/v1/maderas/ensambles');

        $this->assertNotEquals(403, $response->status());
    }

    public function test_backward_compatibility_allows_access_when_permissions_not_configured(): void
    {
        $position = Position::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Operario',
            'permissions' => [],
        ]);

        $user = User::factory()->create([
            'empresa_id' => $this->empresa->id,
            'role' => 'empleado',
            'email_verified_at' => now(),
        ]);

        Empleado::create([
            'empresa_id' => $this->empresa->id,
            'user_id' => $user->id,
            'position_id' => $position->id,
            'full_name' => $user->name,
            'status' => 'active',
            'payment_type' => 'hourly',
        ]);

        $this->seedEmpresaModule('produccion_maderas');

        $response = $this->actingAs($user)->getJson('/api/v1/maderas/inventario');

        $this->assertNotEquals(403, $response->status());
    }

    private function seedEmpresaModule(string $slug): void
    {
        \DB::table('empresa_modules')->insert([
            'id' => (string) Str::uuid(),
            'empresa_id' => $this->empresa->id,
            'module_slug' => $slug,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

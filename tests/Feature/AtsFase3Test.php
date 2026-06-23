<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Empresa;
use App\Models\JobOpening;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtsFase3Test extends TestCase
{
    use RefreshDatabase;

    private function createEmpresa(): Empresa
    {
        return Empresa::create([
            'id' => Str::uuid(),
            'name' => 'Test Corp',
            'slug' => 'test-corp',
        ]);
    }

    private function createUser(string $role, Empresa $empresa, string $email): User
    {
        return User::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createAdmin(): User
    {
        $empresa = $this->createEmpresa();
        return $this->createUser('admin', $empresa, 'admin@example.com');
    }

    private function createCandidate(User $admin): array
    {
        $empresa = Empresa::find($admin->empresa_id);
        $job = JobOpening::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'title' => 'Operador',
            'status' => 'open',
        ]);
        $candidate = $this->createUser('aspirante', $empresa, 'candidate@example.com');
        $app = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $candidate->id,
            'status' => 'interviewing',
        ]);

        return [$app, $candidate, $job, $empresa];
    }

    private function createPosition(Empresa $empresa): Position
    {
        return Position::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name' => 'Operador',
        ]);
    }

    private function portalCookieFor(User $user): string
    {
        return $user->createToken('portal')->plainTextToken;
    }

    public function test_admin_can_send_offer()
    {
        $admin = $this->createAdmin();
        [$app] = $this->createCandidate($admin);
        $position = $this->createPosition(Empresa::find($admin->empresa_id));

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/ats/applications/{$app->id}/offer", [
            'salary' => 250,
            'trial_months' => 2,
            'position_id' => $position->id,
            'notes' => 'Te esperamos pronto.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.salary', '250.00')
            ->assertJsonPath('data.trial_months', 2)
            ->assertJsonPath('data.status', 'sent');

        $app->refresh();
        $this->assertEquals('offer-sent', $app->status);
    }

    public function test_candidate_can_accept_offer()
    {
        $admin = $this->createAdmin();
        [$app, $candidate, , $empresa] = $this->createCandidate($admin);
        $position = $this->createPosition($empresa);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/ats/applications/{$app->id}/offer", [
            'salary' => 250,
            'trial_months' => 2,
            'position_id' => $position->id,
        ]);

        $cookie = $this->portalCookieFor($candidate);

        $response = $this->call(
            'POST',
            '/api/v1/portal/offer/accept',
            ['full_name' => $candidate->name, 'accept_terms' => true],
            ['portal_token' => $cookie],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Oferta aceptada. Bienvenido a Decorarte.');

        $app->refresh();
        $candidate->refresh();

        $this->assertEquals('hired', $app->status);
        $this->assertEquals('empleado_prueba', $candidate->role);
        $this->assertEquals($empresa->id, $candidate->empresa_id);
        $this->assertNotNull($candidate->empleado);
        $this->assertEquals(250, $candidate->empleado->daily_rate);
    }

    public function test_candidate_cannot_accept_offer_with_wrong_name()
    {
        $admin = $this->createAdmin();
        [$app, $candidate] = $this->createCandidate($admin);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/ats/applications/{$app->id}/offer", [
            'salary' => 250,
            'trial_months' => 2,
        ]);

        $cookie = $this->portalCookieFor($candidate);

        $response = $this->call(
            'POST',
            '/api/v1/portal/offer/accept',
            ['full_name' => 'Otro Nombre', 'accept_terms' => true],
            ['portal_token' => $cookie],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El nombre no coincide con el registrado.');
    }

    public function test_admin_can_list_and_verify_onboarding_documents()
    {
        Storage::fake('local');
        $admin = $this->createAdmin();
        [$app, $candidate] = $this->createCandidate($admin);
        $app->update(['status' => 'hired']);

        $cookie = $this->portalCookieFor($candidate);
        $file = UploadedFile::fake()->create('ine.webp', 100, 'image/webp');

        $this->call(
            'POST',
            "/api/v1/portal/applications/{$app->id}/documents",
            ['document_type' => 'ine'],
            ['portal_token' => $cookie],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        )->assertOk();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/ats/applications/{$app->id}/onboarding-documents");
        $response->assertOk();
        $items = $response->json('data');
        $ine = collect($items)->firstWhere('type', 'ine');
        $this->assertTrue($ine['uploaded']);
        $this->assertFalse($ine['verified']);

        $verify = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/ats/applications/{$app->id}/onboarding-documents/ine/verify");
        $verify->assertOk();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/ats/applications/{$app->id}/onboarding-documents");
        $ine = collect($response->json('data'))->firstWhere('type', 'ine');
        $this->assertTrue($ine['verified']);
    }

    public function test_analytics_pipeline_returns_expected_structure()
    {
        $admin = $this->createAdmin();
        [$app] = $this->createCandidate($admin);
        $app->update(['status' => 'hired', 'created_at' => now()->subDays(2)]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/ats/analytics/pipeline');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'totals',
                    'funnel',
                    'average_times',
                    'rejection_reasons',
                    'open_jobs',
                    'upcoming_interviews',
                ],
            ])
            ->assertJsonPath('data.totals.hired', 1);
    }
}

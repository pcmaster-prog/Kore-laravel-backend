<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Empresa;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtsScreeningTest extends TestCase
{
    use RefreshDatabase;

    private function setupEmpresaAndUsers(): array
    {
        $empresa = Empresa::create([
            'id' => Str::uuid(),
            'name' => 'Test Corp',
            'slug' => 'test-corp',
            'settings' => [
                'reclutamiento' => [
                    'welcome_video_url' => 'https://example.com/welcome.mp4',
                ],
            ],
        ]);

        $admin = User::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $aspirante = User::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name' => 'Aspirante',
            'email' => 'aspirante@example.com',
            'password' => Hash::make('password'),
            'role' => 'aspirante',
            'is_active' => true,
        ]);

        return [$empresa, $admin, $aspirante];
    }

    private function createJobOpening(Empresa $empresa, array $overrides = []): JobOpening
    {
        return JobOpening::create(array_merge([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'title' => 'Operador',
            'status' => 'open',
            'screening_questions' => [
                ['question' => 'P1', 'options' => ['A', 'B'], 'correctIndex' => 0],
                ['question' => 'P2', 'options' => ['A', 'B'], 'correctIndex' => 1],
            ],
            'screening_pass_score' => 7,
        ], $overrides));
    }

    private function portalCookieFor(User $user): string
    {
        return $user->createToken('portal')->plainTextToken;
    }

    public function test_public_job_show_hides_correct_index(): void
    {
        [$empresa] = $this->setupEmpresaAndUsers();
        $job = $this->createJobOpening($empresa);

        $response = $this->getJson("/api/v1/public/jobs/{$job->id}?empresa_id={$empresa->id}");

        $response->assertOk();
        $questions = $response->json('data.screening_questions');
        $this->assertCount(2, $questions);
        $this->assertArrayHasKey('question', $questions[0]);
        $this->assertArrayHasKey('options', $questions[0]);
        $this->assertArrayNotHasKey('correctIndex', $questions[0]);
    }

    public function test_public_job_index_includes_welcome_video_url(): void
    {
        [$empresa] = $this->setupEmpresaAndUsers();
        $this->createJobOpening($empresa);

        $response = $this->getJson("/api/v1/public/jobs?empresa_id={$empresa->id}");

        $response->assertOk()
            ->assertJsonPath('meta.welcome_video_url', 'https://example.com/welcome.mp4');
    }

    public function test_screening_passes_and_advances_to_screening(): void
    {
        [$empresa, , $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJobOpening($empresa);
        $application = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $aspirante->id,
            'status' => 'new',
            'has_induction_video_watched' => true,
        ]);

        $token = $this->portalCookieFor($aspirante);

        $response = $this->call(
            'POST',
            "/api/v1/portal/applications/{$application->id}/screening",
            ['answers' => [0, 1]],
            ['portal_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.score', 10);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'screening',
        ]);
    }

    public function test_screening_fails_and_rejects_automatically(): void
    {
        [$empresa, , $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJobOpening($empresa, ['screening_pass_score' => 10]);
        $application = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $aspirante->id,
            'status' => 'new',
            'has_induction_video_watched' => true,
        ]);

        $token = $this->portalCookieFor($aspirante);

        $response = $this->call(
            'POST',
            "/api/v1/portal/applications/{$application->id}/screening",
            ['answers' => [0, 0]],
            ['portal_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.score', 5);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'rejected',
        ]);
    }

    public function test_admin_can_toggle_manual_review(): void
    {
        [$empresa, $admin, $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJobOpening($empresa);
        $application = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $aspirante->id,
            'status' => 'screening',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ats/applications/{$application->id}/manual-review", [
                'manual_review_required' => true,
                'manual_review_reason' => 'Revisar experiencia previa',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.manual_review_required', true)
            ->assertJsonPath('data.manual_review_reason', 'Revisar experiencia previa');
    }

    public function test_admin_can_store_job_with_screening_fields(): void
    {
        [$empresa, $admin] = $this->setupEmpresaAndUsers();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ats/jobs', [
                'title' => 'Cajero',
                'status' => 'open',
                'induction_video_url' => 'https://example.com/induction.mp4',
                'screening_questions' => [
                    ['question' => 'P1', 'options' => ['A', 'B'], 'correctIndex' => 0],
                ],
                'screening_pass_score' => 8,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.induction_video_url', 'https://example.com/induction.mp4')
            ->assertJsonPath('data.screening_pass_score', 8);
    }
}

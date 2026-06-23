<?php

namespace Tests\Feature;

use App\Mail\ApplicationReceivedMail;
use App\Mail\HiredMail;
use App\Mail\InterviewScheduledMail;
use App\Mail\RejectedMail;
use App\Models\Application;
use App\Models\Empresa;
use App\Models\Empleado;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtsFase2Test extends TestCase
{
    use RefreshDatabase;

    private function setupEmpresaAndUsers(): array
    {
        $empresa = Empresa::create([
            'id' => Str::uuid(),
            'name' => 'Test Corp',
            'slug' => 'test-corp',
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

    private function createJob(Empresa $empresa, array $overrides = []): JobOpening
    {
        return JobOpening::create(array_merge([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'title' => 'Operador',
            'status' => 'open',
        ], $overrides));
    }

    private function makeApplication(Empresa $empresa, JobOpening $job, User $user, array $overrides = []): Application
    {
        return Application::create(array_merge([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $user->id,
            'status' => 'screening',
        ], $overrides));
    }

    public function test_admin_can_crud_job_opening_templates(): void
    {
        [$empresa, $admin] = $this->setupEmpresaAndUsers();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ats/job-templates', [
                'title' => 'Cajero',
                'status' => 'draft',
                'description' => 'Desc',
                'requirements' => ['Req 1'],
                'screening_pass_score' => 8,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Cajero');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/ats/job-templates')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_duplicate_job_opening_template(): void
    {
        [$empresa, $admin] = $this->setupEmpresaAndUsers();

        $template = \App\Models\JobOpeningTemplate::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'title' => 'Almacenista',
            'status' => 'draft',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ats/job-templates/{$template->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.title', 'Almacenista (copia)');
    }

    public function test_admin_can_schedule_interview_and_scorecard(): void
    {
        [$empresa, $admin, $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJob($empresa);
        $application = $this->makeApplication($empresa, $job, $aspirante, ['status' => 'interview-requested']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ats/applications/{$application->id}/interview", [
                'interview_scheduled_at' => now()->addDay()->toDateTimeString(),
                'method' => 'video',
                'meeting_url' => 'https://meet.example.com',
                'notes' => 'Entrevista inicial',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'interviewing');

        $interview = $application->fresh()->interviews->first();
        $this->assertNotNull($interview);
        $this->assertEquals('video', $interview->method);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/ats/interviews/{$interview->id}", [
                'scorecard' => [
                    ['name' => 'Experiencia', 'score' => 5, 'notes' => ''],
                    ['name' => 'Actitud', 'score' => 4, 'notes' => ''],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.recommendation', 'Excelente elección');
    }

    public function test_application_received_email_is_sent(): void
    {
        Mail::fake();

        [$empresa, , $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJob($empresa);

        $token = $aspirante->createToken('portal')->plainTextToken;

        $this->call(
            'POST',
            '/api/v1/portal/apply',
            ['job_opening_id' => $job->id],
            ['portal_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        )->assertCreated();

        Mail::assertSent(ApplicationReceivedMail::class, fn ($mail) => $mail->candidateName === 'Aspirante');
    }

    public function test_interview_scheduled_email_is_sent(): void
    {
        Mail::fake();

        [$empresa, $admin, $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJob($empresa);
        $application = $this->makeApplication($empresa, $job, $aspirante, ['status' => 'interview-requested']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ats/applications/{$application->id}/interview", [
                'interview_scheduled_at' => now()->addDay()->toDateTimeString(),
                'method' => 'video',
                'meeting_url' => 'https://meet.example.com',
            ])
            ->assertOk();

        Mail::assertSent(InterviewScheduledMail::class, fn ($mail) => $mail->candidateName === 'Aspirante');
    }

    public function test_reject_email_is_sent(): void
    {
        Mail::fake();

        [$empresa, $admin, $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJob($empresa);
        $application = $this->makeApplication($empresa, $job, $aspirante, ['status' => 'screening']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ats/applications/{$application->id}/reject", [
                'reason' => 'No cumple perfil',
            ])
            ->assertOk();

        Mail::assertSent(RejectedMail::class, fn ($mail) => $mail->candidateName === 'Aspirante' && $mail->reason === 'No cumple perfil');
    }

    public function test_rehire_detects_previous_employee(): void
    {
        [$empresa, $admin, $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJob($empresa);
        $application = $this->makeApplication($empresa, $job, $aspirante, ['status' => 'screening']);

        Empleado::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'user_id' => $aspirante->id,
            'full_name' => $aspirante->name,
            'status' => 'inactive',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/ats/applications/{$application->id}/rehire-check")
            ->assertOk()
            ->assertJsonPath('data.is_rehire', true);
    }

    public function test_rehire_restores_previous_employee(): void
    {
        Mail::fake();

        [$empresa, $admin, $aspirante] = $this->setupEmpresaAndUsers();
        $job = $this->createJob($empresa);
        $application = $this->makeApplication($empresa, $job, $aspirante, ['status' => 'screening']);

        $empleado = Empleado::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'user_id' => $aspirante->id,
            'full_name' => $aspirante->name,
            'status' => 'inactive',
            'daily_rate' => 100,
            'payment_type' => 'daily',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ats/applications/{$application->id}/rehire", [
                'salary' => 150,
            ])
            ->assertOk();

        $empleado->refresh();
        $this->assertEquals('active', $empleado->status);
        $this->assertEquals(150, $empleado->daily_rate);
        $this->assertEquals('hired', $application->fresh()->status);
        Mail::assertSent(HiredMail::class, fn ($mail) => $mail->candidateName === 'Aspirante');
    }
}

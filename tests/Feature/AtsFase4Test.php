<?php

namespace Tests\Feature;

use App\Mail\ApplicationReceivedMail;
use App\Mail\InterviewReminderMail;
use App\Mail\RejectedMail;
use App\Mail\TemplatedEmail;
use App\Models\Application;
use App\Models\EmailTemplate;
use App\Models\Empresa;
use App\Models\Interview;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtsFase4Test extends TestCase
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

    private function setupAdmin(): User
    {
        $empresa = $this->createEmpresa();
        return $this->createUser('admin', $empresa, 'admin@example.com');
    }

    private function createJob(Empresa $empresa, array $overrides = []): JobOpening
    {
        return JobOpening::create(array_merge([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'title' => 'Operador',
            'status' => 'open',
            'location' => 'Ciudad de México',
            'job_type' => 'full-time',
            'department' => 'Operaciones',
            'tags' => ['urgente'],
            'benefits' => ['Seguro social'],
        ], $overrides));
    }

    public function test_admin_can_create_enriched_job_opening()
    {
        $admin = $this->setupAdmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ats/jobs', [
            'title' => 'Cajero',
            'description' => 'Atención al cliente',
            'location' => 'Guadalajara',
            'job_type' => 'part-time',
            'department' => 'Ventas',
            'vacancies_count' => 2,
            'benefits' => ['Vales de despensa'],
            'tags' => ['nuevo'],
            'is_featured' => true,
            'salary_range' => '$8,000 - $10,000',
            'schedule' => 'Lunes a viernes',
            'status' => 'open',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.location', 'Guadalajara')
            ->assertJsonPath('data.job_type', 'part-time')
            ->assertJsonPath('data.department', 'Ventas')
            ->assertJsonPath('data.vacancies_count', 2)
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.slug', 'cajero');
    }

    public function test_public_jobs_support_search_and_filters()
    {
        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);

        $this->createJob($empresa, ['title' => 'Operador de máquina', 'location' => 'CDMX']);
        $this->createJob($empresa, ['title' => 'Cajero', 'location' => 'Guadalajara', 'job_type' => 'part-time', 'department' => 'Ventas']);

        $response = $this->getJson('/api/v1/public/jobs?empresa_id=' . $empresa->id . '&search=operador');
        $response->assertOk()->assertJsonCount(1, 'data');

        $response = $this->getJson('/api/v1/public/jobs?empresa_id=' . $empresa->id . '&location=Guadalajara');
        $response->assertOk()->assertJsonCount(1, 'data');

        $response = $this->getJson('/api/v1/public/jobs?empresa_id=' . $empresa->id . '&job_type=part-time');
        $response->assertOk()->assertJsonCount(1, 'data');

        $response = $this->getJson('/api/v1/public/jobs?empresa_id=' . $empresa->id . '&department=Operaciones');
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_public_filters_returns_available_options()
    {
        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);
        $this->createJob($empresa);

        $response = $this->getJson('/api/v1/public/jobs/filters?empresa_id=' . $empresa->id);

        $response->assertOk()
            ->assertJsonPath('data.locations.0', 'Ciudad de México')
            ->assertJsonPath('data.job_types.0', 'full-time')
            ->assertJsonPath('data.departments.0', 'Operaciones');
    }

    public function test_public_job_show_tracks_views()
    {
        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);
        $job = $this->createJob($empresa);

        $this->getJson('/api/v1/public/jobs/' . $job->id . '?empresa_id=' . $empresa->id)->assertOk();
        $this->getJson('/api/v1/public/jobs/' . $job->id . '?empresa_id=' . $empresa->id)->assertOk();

        $this->assertEquals(1, $job->fresh()->views()->count());
    }

    public function test_public_job_show_accepts_slug_identifier()
    {
        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);
        $job = $this->createJob($empresa, ['slug' => 'operador-especial']);

        $this->getJson('/api/v1/public/jobs/operador-especial?empresa_id=' . $empresa->id)
            ->assertOk()
            ->assertJsonPath('data.id', $job->id);
    }

    public function test_admin_can_set_custom_slug()
    {
        $admin = $this->setupAdmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/ats/jobs', [
            'title' => 'Cajero',
            'slug' => 'cajero-zapopan-2026',
            'status' => 'open',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'cajero-zapopan-2026');
    }

    public function test_interview_reminder_command_sends_emails()
    {
        Mail::fake();

        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);
        $candidate = $this->createUser('aspirante', $empresa, 'candidate@example.com');
        $interviewer = $this->createUser('admin', $empresa, 'interviewer@example.com');
        $job = $this->createJob($empresa);
        $application = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $candidate->id,
            'status' => 'interviewing',
        ]);

        Interview::create([
            'id' => Str::uuid(),
            'application_id' => $application->id,
            'interviewer_id' => $interviewer->id,
            'scheduled_at' => now()->addHours(24),
            'method' => 'video',
            'result' => 'pending',
        ]);

        $this->artisan('interviews:send-reminders')->assertSuccessful();

        Mail::assertQueued(InterviewReminderMail::class, 2);
    }

    public function test_screening_failure_sends_rejection_email()
    {
        Mail::fake();

        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);
        $candidate = $this->createUser('aspirante', $empresa, 'candidate@example.com');
        $job = $this->createJob($empresa, [
            'screening_questions' => [
                ['text' => 'P1', 'options' => ['a', 'b'], 'correctIndex' => 0],
                ['text' => 'P2', 'options' => ['a', 'b'], 'correctIndex' => 0],
            ],
            'screening_pass_score' => 10,
        ]);
        $application = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $candidate->id,
            'status' => 'new',
            'has_induction_video_watched' => true,
        ]);

        $token = $candidate->createToken('portal')->plainTextToken;

        $this->call(
            'POST',
            "/api/v1/portal/applications/{$application->id}/screening",
            ['answers' => [1, 1]],
            ['portal_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        )->assertOk();

        Mail::assertSent(RejectedMail::class);
    }

    public function test_custom_email_template_is_used_when_active()
    {
        Mail::fake();

        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);

        EmailTemplate::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'type' => 'application_received',
            'subject' => 'Gracias {{ $candidateName }}',
            'body' => '<p>Hola {{ $candidateName }}, recibimos tu postulación a {{ $jobTitle }}.</p>',
            'is_active' => true,
        ]);

        $candidate = $this->createUser('aspirante', $empresa, 'candidate@example.com');
        $job = $this->createJob($empresa);
        $token = $candidate->createToken('portal')->plainTextToken;

        $this->call(
            'POST',
            '/api/v1/portal/apply',
            ['job_opening_id' => $job->id],
            ['portal_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        )->assertCreated();

        Mail::assertQueued(TemplatedEmail::class, fn ($mail) => $mail->emailSubject === 'Gracias Test User');
    }

    public function test_interview_scheduled_sends_whatsapp_when_phone_present()
    {
        Http::fake();
        Mail::fake();
        config(['services.whatsapp.api_key' => 'testkey', 'services.whatsapp.phone' => '524626269090']);

        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);
        $candidate = $this->createUser('aspirante', $empresa, 'candidate@example.com');
        $job = $this->createJob($empresa);
        $application = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $candidate->id,
            'status' => 'interview-requested',
            'contact_info' => ['phone' => '5512345678'],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ats/applications/{$application->id}/interview", [
                'interview_scheduled_at' => now()->addDay()->toDateTimeString(),
                'method' => 'video',
                'meeting_url' => 'https://meet.example.com',
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.callmebot.com'));
    }

    public function test_offer_sent_sends_whatsapp_when_phone_present()
    {
        Http::fake();
        Mail::fake();
        config(['services.whatsapp.api_key' => 'testkey', 'services.whatsapp.phone' => '524626269090']);

        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);
        $candidate = $this->createUser('aspirante', $empresa, 'candidate@example.com');
        $job = $this->createJob($empresa);
        $application = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $candidate->id,
            'status' => 'interviewing',
            'contact_info' => ['phone' => '5512345678'],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/ats/applications/{$application->id}/offer", [
                'salary' => 100,
                'trial_months' => 1,
            ])
            ->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.callmebot.com'));
    }

    public function test_offer_sent_uses_custom_template_when_active()
    {
        Mail::fake();

        $admin = $this->setupAdmin();
        $empresa = Empresa::find($admin->empresa_id);

        EmailTemplate::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'type' => 'offer_sent',
            'subject' => 'Tu oferta {{ $jobTitle }}',
            'body' => '<p>Hola {{ $candidateName }}, revisa tu oferta en {{ $offerUrl }}.</p>',
            'is_active' => true,
        ]);

        $candidate = $this->createUser('aspirante', $empresa, 'candidate@example.com');
        $job = $this->createJob($empresa);
        $app = Application::create([
            'id' => Str::uuid(),
            'empresa_id' => $empresa->id,
            'job_opening_id' => $job->id,
            'user_id' => $candidate->id,
            'status' => 'interviewing',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/ats/applications/{$app->id}/offer", [
            'salary' => 100,
            'trial_months' => 1,
        ])->assertCreated();

        Mail::assertQueued(TemplatedEmail::class, fn ($mail) => $mail->emailSubject === 'Tu oferta Operador');
    }

    public function test_admin_can_manage_email_templates()
    {
        $admin = $this->setupAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/ats/email-templates', [
                'type' => 'rejected',
                'subject' => 'Lo sentimos',
                'body' => '<p>Hola {{ $candidateName }}.</p>',
            ])
            ->assertCreated();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/ats/email-templates');
        $response->assertOk()->assertJsonCount(1, 'data');

        $template = $response->json('data.0');
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/ats/email-templates/' . $template['id'], [
                'subject' => 'Actualizado',
            ])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Actualizado');
    }
}

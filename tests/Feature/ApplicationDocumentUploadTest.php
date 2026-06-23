<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Empresa;
use App\Models\JobOpening;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function setupPortalUserAndApplication(): array
    {
        $empresa = Empresa::create([
            'id'   => Str::uuid(),
            'name' => 'Test Corp',
            'slug' => 'test-corp',
        ]);

        $user = User::create([
            'id'         => Str::uuid(),
            'empresa_id' => $empresa->id,
            'name'       => 'Aspirante',
            'email'      => 'aspirante@example.com',
            'password'   => Hash::make('password'),
            'role'       => 'aspirante',
            'is_active'  => true,
        ]);

        $job = JobOpening::create([
            'id'          => Str::uuid(),
            'empresa_id'  => $empresa->id,
            'title'       => 'Operador',
            'status'      => 'open',
        ]);

        $application = Application::create([
            'id'            => Str::uuid(),
            'empresa_id'    => $empresa->id,
            'job_opening_id'=> $job->id,
            'user_id'       => $user->id,
            'status'        => 'pending',
        ]);

        return [$user, $application];
    }

    private function portalCookieFor(User $user): string
    {
        $token = $user->createToken('portal');
        return $token->plainTextToken;
    }

    public function test_portal_candidate_can_upload_webp_document(): void
    {
        Storage::fake('local');

        [$user, $application] = $this->setupPortalUserAndApplication();
        $cookieValue = $this->portalCookieFor($user);

        $file = UploadedFile::fake()->create('comprobante.webp', 100, 'image/webp');

        $res = $this->call(
            'POST',
            "/api/v1/portal/applications/{$application->id}/documents",
            ['document_type' => 'address_proof'],
            ['portal_token' => $cookieValue],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $res->assertOk()
            ->assertJsonPath('data.document_type', 'address_proof');

        $this->assertDatabaseHas('application_documents', [
            'application_id' => $application->id,
            'document_type'  => 'address_proof',
        ]);

        Storage::disk('local')->assertExists('applications/' . $application->id . '/' . $file->hashName());
    }

    public function test_portal_rejects_unsupported_document_type(): void
    {
        [$user, $application] = $this->setupPortalUserAndApplication();
        $cookieValue = $this->portalCookieFor($user);

        $file = UploadedFile::fake()->create('document.gif', 100, 'image/gif');

        $res = $this->call(
            'POST',
            "/api/v1/portal/applications/{$application->id}/documents",
            ['document_type' => 'address_proof'],
            ['portal_token' => $cookieValue],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        );
        $res->assertUnprocessable();
    }
}

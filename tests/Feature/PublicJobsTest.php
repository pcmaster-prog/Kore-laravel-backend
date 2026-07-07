<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Smoke tests para los endpoints públicos del portal de vacantes.
 *
 * Validan que el middleware ResolvePublicTenant aísla correctamente
 * los datos por tenant, incluyendo los dos endpoints sin identificador
 * explícito: GET /public/jobs y GET /public/jobs/filters.
 *
 * Cobertura (Gap 1 & Gap 2 — Multi-Tenant Security Fase A):
 * - /public/jobs        → solo retorna jobs del tenant del Host
 * - /public/jobs/filters → solo retorna filtros del tenant del Host
 * - /public/jobs/{id}  → solo retorna job si pertenece al tenant del Host
 * - Host no registrado → 404 limpio, sin fuga de datos de otros tenants
 * - Job de otro tenant → 404, no accesible vía Host de primer tenant
 */
class PublicJobsTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createEmpresa(string $slug, string $domain): Empresa
    {
        return Empresa::create([
            'id'     => Str::uuid(),
            'name'   => "Empresa {$slug}",
            'slug'   => $slug,
            'domain' => $domain,
        ]);
    }

    private function createJob(Empresa $empresa, array $overrides = []): JobOpening
    {
        return JobOpening::create(array_merge([
            'id'         => Str::uuid(),
            'empresa_id' => $empresa->id,
            'title'      => 'Vacante Test',
            'status'     => 'open',
            'slug'       => Str::slug('Vacante Test ' . Str::random(5)),
        ], $overrides));
    }

    // Simula una petición al portal público con el Host header correcto
    private function publicRequest(string $method, string $url, string $host): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Host' => $host])
            ->json($method, "/api/v1{$url}");
    }

    // ─── Tests: /public/jobs ─────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_jobs_index_returns_only_jobs_of_correct_tenant(): void
    {
        $empresaA = $this->createEmpresa('empresa-a', 'vacantes.empresa-a.com');
        $empresaB = $this->createEmpresa('empresa-b', 'vacantes.empresa-b.com');

        $jobA = $this->createJob($empresaA, ['title' => 'Job de Empresa A']);
        $jobB = $this->createJob($empresaB, ['title' => 'Job de Empresa B']);

        // Petición al portal de Empresa A
        $response = $this->publicRequest('GET', '/public/jobs', 'vacantes.empresa-a.com');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->toArray();

        $this->assertContains('Job de Empresa A', $titles, 'El job de Empresa A debe aparecer');
        $this->assertNotContains('Job de Empresa B', $titles, 'El job de Empresa B NO debe aparecer (fuga cross-tenant)');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_jobs_index_does_not_return_closed_jobs(): void
    {
        $empresa = $this->createEmpresa('empresa-c', 'vacantes.empresa-c.com');

        $this->createJob($empresa, ['title' => 'Vacante Abierta', 'status' => 'open']);
        $this->createJob($empresa, ['title' => 'Vacante Cerrada', 'status' => 'closed']);

        $response = $this->publicRequest('GET', '/public/jobs', 'vacantes.empresa-c.com');

        $response->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->toArray();

        $this->assertContains('Vacante Abierta', $titles);
        $this->assertNotContains('Vacante Cerrada', $titles);
    }

    // ─── Tests: /public/jobs/filters ─────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_jobs_filters_returns_only_data_of_correct_tenant(): void
    {
        $empresaA = $this->createEmpresa('empresa-d', 'vacantes.empresa-d.com');
        $empresaB = $this->createEmpresa('empresa-e', 'vacantes.empresa-e.com');

        $this->createJob($empresaA, ['location' => 'Ciudad de México', 'department' => 'Ventas']);
        $this->createJob($empresaB, ['location' => 'Guadalajara',      'department' => 'Cocina']);

        // Petición al portal de Empresa A
        $response = $this->publicRequest('GET', '/public/jobs/filters', 'vacantes.empresa-d.com');

        $response->assertOk();

        $locations  = $response->json('data.locations');
        $departments = $response->json('data.departments');

        $this->assertContains('Ciudad de México', $locations, 'Location de Empresa A debe aparecer');
        $this->assertNotContains('Guadalajara', $locations,   'Location de Empresa B NO debe aparecer');

        $this->assertContains('Ventas', $departments, 'Department de Empresa A debe aparecer');
        $this->assertNotContains('Cocina', $departments, 'Department de Empresa B NO debe aparecer');
    }

    // ─── Tests: /public/jobs/{id} ────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_jobs_show_returns_job_of_correct_tenant(): void
    {
        $empresa = $this->createEmpresa('empresa-f', 'vacantes.empresa-f.com');
        $job     = $this->createJob($empresa, ['title' => 'Job visible']);

        $response = $this->publicRequest('GET', "/public/jobs/{$job->id}", 'vacantes.empresa-f.com');

        $response->assertOk();
        $this->assertEquals('Job visible', $response->json('data.title'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_jobs_show_returns_404_for_job_from_different_tenant(): void
    {
        $empresaA = $this->createEmpresa('empresa-g', 'vacantes.empresa-g.com');
        $empresaB = $this->createEmpresa('empresa-h', 'vacantes.empresa-h.com');

        $jobB = $this->createJob($empresaB, ['title' => 'Job de Empresa B']);

        // Intentar acceder al job de Empresa B usando el Host de Empresa A
        $response = $this->publicRequest('GET', "/public/jobs/{$jobB->id}", 'vacantes.empresa-g.com');

        $response->assertNotFound();
    }

    // ─── Tests: Host no registrado → 404 limpio ──────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_jobs_index_returns_404_for_unknown_host(): void
    {
        $response = $this->publicRequest('GET', '/public/jobs', 'vacantes.empresa-inexistente.com');

        // 404 limpio — sin stack trace ni datos de otros tenants
        $response->assertNotFound();

        // Asegurar que no hay datos de jobs en la respuesta
        $this->assertNull($response->json('data'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_jobs_filters_returns_404_for_unknown_host(): void
    {
        $response = $this->publicRequest('GET', '/public/jobs/filters', 'vacantes.dominio-inexistente.mx');

        $response->assertNotFound();
        $this->assertNull($response->json('data'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function public_jobs_index_returns_empty_array_when_tenant_has_no_jobs(): void
    {
        $empresa = $this->createEmpresa('empresa-vacia', 'vacantes.empresa-vacia.com');

        $response = $this->publicRequest('GET', '/public/jobs', 'vacantes.empresa-vacia.com');

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }
}

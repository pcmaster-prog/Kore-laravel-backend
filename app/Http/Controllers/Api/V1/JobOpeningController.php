<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\JobOpening;
use Illuminate\Support\Facades\Gate;

class JobOpeningController extends Controller
{
    // === PÚBLICO ===
    private function resolveEmpresaId(Request $request): ?string
    {
        if ($request->has('empresa_id')) {
            return $request->input('empresa_id');
        }

        if ($request->has('empresa_slug')) {
            $empresa = \App\Models\Empresa::where('slug', $request->input('empresa_slug'))->first();
            return $empresa?->id;
        }

        $defaultSlug = config('app.default_empresa_slug');
        if ($defaultSlug) {
            $empresa = \App\Models\Empresa::where('slug', $defaultSlug)->first();
            return $empresa?->id;
        }

        return null;
    }

    public function publicIndex(Request $request)
    {
        $empresaId = $this->resolveEmpresaId($request);

        $query = JobOpening::where('status', 'open');

        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        $jobs = $query->orderBy('created_at', 'desc')->get()->map(function (JobOpening $job) {
            return $this->presentPublicJob($job);
        });

        return response()->json([
            'data' => $jobs,
            'meta' => [
                'welcome_video_url' => $this->welcomeVideoUrl($jobs->first()?->empresa_id ?? $this->resolveEmpresaId($request)),
            ],
        ]);
    }

    public function publicShow(Request $request, $id)
    {
        $empresaId = $this->resolveEmpresaId($request);

        $query = JobOpening::where('id', $id)->where('status', 'open');

        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        $job = $query->firstOrFail();

        return response()->json([
            'data' => $this->presentPublicJob($job, includeQuestions: true),
            'meta' => [
                'welcome_video_url' => $this->welcomeVideoUrl($job->empresa_id),
            ],
        ]);
    }

    private function welcomeVideoUrl(?string $empresaId): ?string
    {
        if (! $empresaId) {
            return null;
        }

        $empresa = \App\Models\Empresa::find($empresaId);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];

        return $settings['reclutamiento']['welcome_video_url'] ?? null;
    }

    private function presentPublicJob(JobOpening $job, bool $includeQuestions = false): array
    {
        $data = $job->toArray();

        if (! $includeQuestions) {
            unset($data['screening_questions']);
        } else {
            $data['screening_questions'] = $this->sanitizePublicQuestions($job->screening_questions ?? []);
        }

        return $data;
    }

    private function sanitizePublicQuestions(array $questions): array
    {
        return array_map(function ($q) {
            if (is_array($q)) {
                unset($q['correctIndex']);
            }
            return $q;
        }, $questions);
    }

    // === ADMIN (Kore ERP) ===
    public function index(Request $request)
    {
        Gate::authorize('manage-users'); // O algun permiso de RRHH
        $jobs = JobOpening::where('empresa_id', $request->user()->empresa_id)->get();
        return response()->json(['data' => $jobs]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-users');
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'requirements' => 'nullable|array',
            'salary_range' => 'nullable|string',
            'schedule' => 'nullable|string',
            'status' => 'required|in:draft,open,closed',
            'image_url' => 'nullable|string|url',
            'induction_video_url' => 'nullable|string|url',
            'screening_questions' => 'nullable|array',
            'screening_pass_score' => 'nullable|integer|min:1|max:10',
        ]);

        $job = JobOpening::create([
            'empresa_id' => $request->user()->empresa_id,
            ...$validated
        ]);

        return response()->json(['data' => $job], 201);
    }

    public function show(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $job = JobOpening::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);
        return response()->json(['data' => $job]);
    }

    public function update(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $job = JobOpening::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'requirements' => 'nullable|array',
            'salary_range' => 'nullable|string',
            'schedule' => 'nullable|string',
            'status' => 'sometimes|in:draft,open,closed',
            'image_url' => 'nullable|string|url',
            'induction_video_url' => 'nullable|string|url',
            'screening_questions' => 'nullable|array',
            'screening_pass_score' => 'nullable|integer|min:1|max:10',
        ]);

        $job->update($validated);

        return response()->json(['data' => $job]);
    }

    public function destroy(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $job = JobOpening::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);
        $job->delete();

        return response()->json(['message' => 'Job opening deleted.']);
    }
}

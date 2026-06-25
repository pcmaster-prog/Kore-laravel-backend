<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\JobOpening;
use App\Models\JobOpeningView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class JobOpeningController extends Controller
{
    // === PÚBLICO ===
    private function resolveEmpresaId(Request $request): ?string
    {
        if ($request->has('empresa_id')) {
            return $request->input('empresa_id');
        }

        if ($request->has('empresa_slug')) {
            $empresa = Empresa::where('slug', $request->input('empresa_slug'))->first();

            return $empresa?->id;
        }

        $defaultSlug = config('app.default_empresa_slug');
        if ($defaultSlug) {
            $empresa = Empresa::where('slug', $defaultSlug)->first();

            return $empresa?->id;
        }

        return null;
    }

    public function publicIndex(Request $request)
    {
        $empresaId = $this->resolveEmpresaId($request);

        $query = JobOpening::where('status', 'open')
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });

        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        // Filtros
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        if ($request->filled('location')) {
            $query->where('location', $request->input('location'));
        }

        if ($request->filled('job_type')) {
            $query->where('job_type', $request->input('job_type'));
        }

        if ($request->filled('department')) {
            $query->where('department', $request->input('department'));
        }

        // Ordenamiento
        $sort = $request->input('sort', 'published_at');
        $direction = strtolower($request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['published_at', 'created_at', 'title', 'vacancies_count'];

        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $direction);
        } else {
            $query->orderBy('published_at', 'desc')->orderBy('created_at', 'desc');
        }

        // Destacadas primero
        $query->orderBy('is_featured', 'desc');

        $perPage = (int) $request->input('per_page', 12);
        $perPage = max(1, min($perPage, 100));

        $jobs = $query->paginate($perPage);

        $data = $jobs->through(function (JobOpening $job) {
            return $this->presentPublicJob($job);
        });

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'welcome_video_url' => $this->welcomeVideoUrl($empresaId),
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
            ],
        ]);
    }

    public function publicShow(Request $request, $id)
    {
        $empresaId = $this->resolveEmpresaId($request);

        $job = $this->findPublicJob($id, $empresaId);

        $this->trackView($job, $request);

        return response()->json([
            'data' => $this->presentPublicJob($job, includeQuestions: true),
            'meta' => [
                'welcome_video_url' => $this->welcomeVideoUrl($job->empresa_id),
            ],
        ]);
    }

    private function findPublicJob(string $identifier, ?string $empresaId): JobOpening
    {
        $query = JobOpening::where('status', 'open');
        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        if (Str::isUuid($identifier)) {
            $job = (clone $query)->where('id', $identifier)->first();
            if ($job) {
                return $job;
            }
        }

        return (clone $query)->where('slug', $identifier)->firstOrFail();
    }

    public function publicFilters(Request $request)
    {
        $empresaId = $this->resolveEmpresaId($request);

        $query = JobOpening::where('status', 'open');
        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        $cacheKey = 'job_opening_filters:'.($empresaId ?? 'global');

        $filters = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($query) {
            return [
                'locations' => (clone $query)->whereNotNull('location')->distinct()->pluck('location')->filter()->values(),
                'job_types' => (clone $query)->whereNotNull('job_type')->distinct()->pluck('job_type')->filter()->values(),
                'departments' => (clone $query)->whereNotNull('department')->distinct()->pluck('department')->filter()->values(),
            ];
        });

        return response()->json(['data' => $filters]);
    }

    private function trackView(JobOpening $job, Request $request): void
    {
        $ip = $request->ip();
        $cacheKey = "job_view:{$job->id}:{$ip}";

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addHour());

        JobOpeningView::create([
            'id' => Str::uuid(),
            'job_opening_id' => $job->id,
            'source' => $request->input('source'),
            'ip_address' => $ip,
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function welcomeVideoUrl(?string $empresaId): ?string
    {
        if (! $empresaId) {
            return null;
        }

        $empresa = Empresa::find($empresaId);
        $settings = is_array($empresa?->settings) ? $empresa->settings : [];

        return $settings['reclutamiento']['welcome_video_url'] ?? null;
    }

    private function presentPublicJob(JobOpening $job, bool $includeQuestions = false): array
    {
        $data = $job->toArray();
        $data['views_count'] = $job->views()->count();

        $structuredJsonFields = [
            'responsibilities',
            'education_requirements',
            'experience_requirements',
            'knowledge_requirements',
            'competencies',
            'performance_indicators',
            'offer_details',
        ];

        foreach ($structuredJsonFields as $field) {
            $data[$field] = $job->{$field} ?? [];
        }

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
        Gate::authorize('manage-users');
        $jobs = JobOpening::where('empresa_id', $request->user()->empresa_id)
            ->withCount('views')
            ->get();

        return response()->json(['data' => $jobs]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-users');
        $validated = $request->validate($this->jobRules());

        if (empty($validated['slug'])) {
            $validated['slug'] = $this->generateSlug($validated['title'], $request->user()->empresa_id);
        }

        $job = JobOpening::create([
            'empresa_id' => $request->user()->empresa_id,
            ...$validated,
        ]);

        return response()->json(['data' => $job], 201);
    }

    public function show(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $job = JobOpening::where('empresa_id', $request->user()->empresa_id)
            ->withCount('views')
            ->findOrFail($id);

        return response()->json(['data' => $job]);
    }

    public function update(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $job = JobOpening::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate($this->jobRules(true));

        if (isset($validated['title']) && empty($job->slug)) {
            $validated['slug'] = $this->generateSlug($validated['title'], $request->user()->empresa_id);
        }

        $job->update($validated);

        return response()->json(['data' => $job->fresh()]);
    }

    public function destroy(Request $request, $id)
    {
        Gate::authorize('manage-users');
        $job = JobOpening::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);
        $job->delete();

        return response()->json(['message' => 'Job opening deleted.']);
    }

    private function jobRules(bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return [
            'title' => ($partial ? 'sometimes' : 'required').'|string|max:255',
            'description' => 'nullable|string',
            'requirements' => 'nullable|array',
            'about_us' => 'sometimes|nullable|string',
            'objective' => 'sometimes|nullable|string',
            'responsibilities' => 'sometimes|nullable|array',
            'education_requirements' => 'sometimes|nullable|array',
            'experience_requirements' => 'sometimes|nullable|array',
            'knowledge_requirements' => 'sometimes|nullable|array',
            'competencies' => 'sometimes|nullable|array',
            'performance_indicators' => 'sometimes|nullable|array',
            'offer_details' => 'sometimes|nullable|array',
            'closing_statement' => 'sometimes|nullable|string',
            'salary_range' => 'nullable|string|max:255',
            'schedule' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'job_type' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'vacancies_count' => 'nullable|integer|min:1',
            'benefits' => 'nullable|array',
            'tags' => 'nullable|array',
            'is_featured' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'slug' => 'nullable|string|max:255',
            'status' => $sometimes.'|in:draft,open,closed',
            'image_url' => 'nullable|string|url',
            'induction_video_url' => 'nullable|string|url',
            'screening_questions' => 'nullable|array',
            'screening_pass_score' => 'nullable|integer|min:1|max:10',
            'scorecard_template' => 'nullable|array',
        ];
    }

    private function generateSlug(string $title, string $empresaId): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $counter = 1;

        while (JobOpening::where('empresa_id', $empresaId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}

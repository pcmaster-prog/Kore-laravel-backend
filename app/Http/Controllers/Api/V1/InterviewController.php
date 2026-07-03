<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Interview;
use App\Services\InterviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InterviewController extends Controller
{
    public function index(Request $request, $applicationId)
    {
        Gate::authorize('manage-users');

        $application = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($applicationId);
        $interviews = $application->interviews()->with(['interviewer', 'creator'])->orderBy('scheduled_at', 'desc')->get();

        return response()->json(['data' => $interviews]);
    }

    public function store(Request $request, $applicationId)
    {
        Gate::authorize('manage-users');

        $application = Application::where('empresa_id', $request->user()->empresa_id)->findOrFail($applicationId);

        $validated = $request->validate([
            'scheduled_at' => 'required|date',
            'method' => 'required|in:in-person,video,phone',
            'location' => 'nullable|string',
            'meeting_url' => 'nullable|string|url',
            'notes' => 'nullable|string',
            'interviewer_id' => 'nullable|exists:users,id',
        ]);

        $interview = Interview::create([
            'application_id' => $application->id,
            'interviewer_id' => $validated['interviewer_id'] ?? null,
            'scheduled_at' => $validated['scheduled_at'],
            'method' => $validated['method'],
            'location' => $validated['location'] ?? null,
            'meeting_url' => $validated['meeting_url'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $interview->load(['interviewer', 'creator'])], 201);
    }

    public function show(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $interview = Interview::with(['application', 'interviewer', 'creator'])
            ->whereHas('application', fn ($q) => $q->where('empresa_id', $request->user()->empresa_id))
            ->findOrFail($id);

        return response()->json(['data' => $interview]);
    }

    public function update(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $interview = Interview::whereHas('application', fn ($q) => $q->where('empresa_id', $request->user()->empresa_id))
            ->findOrFail($id);

        $validated = $request->validate([
            'scheduled_at' => 'sometimes|date',
            'method' => 'sometimes|in:in-person,video,phone',
            'location' => 'nullable|string',
            'meeting_url' => 'nullable|string|url',
            'notes' => 'nullable|string',
            'interviewer_id' => 'nullable|exists:users,id',
            'scorecard' => 'nullable|array',
            'scorecard.*.name' => 'required_with:scorecard|string',
            'scorecard.*.score' => 'required_with:scorecard|integer|min:1|max:5',
            'scorecard.*.notes' => 'nullable|string',
            'result' => 'sometimes|in:pending,passed,failed',
            'document_checklist' => 'nullable|array',
            'document_checklist.*.type' => 'required_with:document_checklist|string',
            'document_checklist.*.status' => 'required_with:document_checklist|in:presented,missing,pending',
            'document_checklist.*.notes' => 'nullable|string',
        ]);

        if (isset($validated['scorecard'])) {
            $validated['recommendation'] = InterviewService::calculateRecommendation($validated['scorecard']);
        }

        $interview->update($validated);

        return response()->json(['data' => $interview->load(['interviewer', 'creator'])]);
    }

    public function destroy(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $interview = Interview::whereHas('application', fn ($q) => $q->where('empresa_id', $request->user()->empresa_id))
            ->findOrFail($id);

        $interview->delete();

        return response()->json(['message' => 'Entrevista eliminada.']);
    }
}

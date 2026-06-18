<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\JobOpening;
use Illuminate\Support\Facades\Gate;

class JobOpeningController extends Controller
{
    // === PÚBLICO ===
    public function publicIndex()
    {
        // Solo las vacantes abiertas
        $jobs = JobOpening::where('status', 'open')->get();
        return response()->json(['data' => $jobs]);
    }

    public function publicShow($id)
    {
        $job = JobOpening::where('id', $id)->where('status', 'open')->firstOrFail();
        return response()->json(['data' => $job]);
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

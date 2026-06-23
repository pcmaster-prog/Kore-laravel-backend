<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOpeningTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class JobOpeningTemplateController extends Controller
{
    private function validationRules(bool $isUpdate = false): array
    {
        return [
            'title' => ($isUpdate ? 'sometimes|' : 'required|').'string|max:255',
            'description' => 'nullable|string',
            'requirements' => 'nullable|array',
            'salary_range' => 'nullable|string',
            'schedule' => 'nullable|string',
            'status' => ($isUpdate ? 'sometimes|' : 'required|').'in:draft,open,closed',
            'image_url' => 'nullable|string|url',
            'induction_video_url' => 'nullable|string|url',
            'screening_questions' => 'nullable|array',
            'screening_pass_score' => 'nullable|integer|min:1|max:10',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request)
    {
        Gate::authorize('manage-users');

        $templates = JobOpeningTemplate::where('empresa_id', $request->user()->empresa_id)
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-users');

        $validated = $request->validate($this->validationRules());

        $template = JobOpeningTemplate::create([
            'empresa_id' => $request->user()->empresa_id,
            ...$validated,
        ]);

        return response()->json(['data' => $template], 201);
    }

    public function show(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $template = JobOpeningTemplate::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        return response()->json(['data' => $template]);
    }

    public function update(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $template = JobOpeningTemplate::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);
        $validated = $request->validate($this->validationRules(true));
        $template->update($validated);

        return response()->json(['data' => $template]);
    }

    public function destroy(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $template = JobOpeningTemplate::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);
        $template->delete();

        return response()->json(['message' => 'Plantilla eliminada.']);
    }

    public function duplicate(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $template = JobOpeningTemplate::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $copy = $template->replicate();
        $copy->id = (string) Str::uuid();
        $copy->title = $copy->title.' (copia)';
        $copy->is_active = true;
        $copy->save();

        return response()->json(['data' => $copy], 201);
    }
}

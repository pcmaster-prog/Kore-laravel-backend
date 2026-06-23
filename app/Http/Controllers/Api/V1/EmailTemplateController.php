<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    private const TYPES = [
        'application_received',
        'interview_scheduled',
        'interview_reminder',
        'offer_sent',
        'hired',
        'rejected',
    ];

    public function index(Request $request)
    {
        Gate::authorize('manage-users');

        $templates = EmailTemplate::where('empresa_id', $request->user()->empresa_id)
            ->orderBy('type')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-users');

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);

        $template = EmailTemplate::updateOrCreate(
            [
                'empresa_id' => $request->user()->empresa_id,
                'type' => $validated['type'],
            ],
            [
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        return response()->json(['data' => $template], 201);
    }

    public function show(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $template = EmailTemplate::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        return response()->json(['data' => $template]);
    }

    public function update(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $template = EmailTemplate::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(self::TYPES)],
            'subject' => 'sometimes|string|max:255',
            'body' => 'sometimes|string',
            'is_active' => 'nullable|boolean',
        ]);

        $template->update($validated);

        return response()->json(['data' => $template->fresh()]);
    }

    public function destroy(Request $request, $id)
    {
        Gate::authorize('manage-users');

        $template = EmailTemplate::where('empresa_id', $request->user()->empresa_id)->findOrFail($id);
        $template->delete();

        return response()->json(['message' => 'Plantilla eliminada.']);
    }

    public function types(Request $request)
    {
        Gate::authorize('manage-users');

        return response()->json(['data' => self::TYPES]);
    }
}

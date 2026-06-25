<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\TaskAssignmentRule;
use App\Models\TaskAssignmentRuleItem;
use App\Models\TaskTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskAssignmentRulesController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $q = TaskAssignmentRule::where('empresa_id', $u->empresa_id)
            ->with(['items.template', 'section']);

        if ($request->filled('section_id')) {
            $q->where('section_id', $request->string('section_id'));
        }

        if ($request->filled('empleado_id')) {
            $q->where('assignee_type', 'empleado')
                ->where('assignee_id', $request->string('empleado_id'));
        }

        if ($request->filled('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        return response()->json($q->orderBy('created_at', 'desc')->paginate(50));
    }

    public function store(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        return $this->createRule($request, $u);
    }

    public function bulkStore(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.template_ids' => ['required', 'array', 'min:1'],
            'rules.*.template_ids.*' => ['uuid', 'exists:task_templates,id'],
            'rules.*.assignee_type' => ['required', Rule::in(['empleado', 'position', 'section_supervisor'])],
            'rules.*.assignee_id' => ['nullable', 'uuid'],
            'rules.*.section_id' => ['nullable', 'uuid'],
            'rules.*.day_of_week' => ['required', 'array'],
            'rules.*.day_of_week.*' => ['integer', 'min:0', 'max:6'],
            'rules.*.trigger_time' => ['nullable', 'date_format:H:i'],
            'rules.*.trigger_event' => ['nullable', Rule::in(['time', 'attendance_checkin', 'both'])],
        ]);

        $created = [];
        foreach ($data['rules'] as $ruleData) {
            $ruleRequest = new Request($ruleData);
            $ruleRequest->setUserResolver(fn () => $u);
            $result = $this->createRule($ruleRequest, $u);
            if ($result->getStatusCode() === 201) {
                $created[] = json_decode($result->getContent(), true)['item'];
            }
        }

        return response()->json(['items' => $created], 201);
    }

    private function createRule(Request $request, $user)
    {
        $data = $request->validate([
            'template_ids' => ['required', 'array', 'min:1'],
            'template_ids.*' => ['uuid', 'exists:task_templates,id'],
            'assignee_type' => ['required', Rule::in(['empleado', 'position', 'section_supervisor'])],
            'assignee_id' => ['nullable', 'uuid'],
            'section_id' => ['nullable', 'uuid', 'exists:sections,id'],
            'day_of_week' => ['required', 'array'],
            'day_of_week.*' => ['integer', 'min:0', 'max:6'],
            'trigger_time' => ['nullable', 'date_format:H:i'],
            'trigger_event' => ['nullable', Rule::in(['time', 'attendance_checkin', 'both'])],
        ]);

        // Validar que los templates pertenezcan a la empresa
        $validTemplates = TaskTemplate::where('empresa_id', $user->empresa_id)
            ->whereIn('id', $data['template_ids'])
            ->pluck('id')
            ->all();

        if (count($validTemplates) !== count($data['template_ids'])) {
            return response()->json(['message' => 'Uno o más templates no pertenecen a la empresa'], 422);
        }

        // Validar sección si se proporciona
        if (! empty($data['section_id'])) {
            $section = Section::where('empresa_id', $user->empresa_id)->where('id', $data['section_id'])->first();
            if (! $section) {
                return response()->json(['message' => 'Sección no encontrada'], 404);
            }
        }

        if ($data['assignee_type'] === 'section_supervisor' && empty($data['section_id'])) {
            return response()->json(['message' => 'section_id es requerido para section_supervisor'], 422);
        }

        $firstTemplateId = $data['template_ids'][0];

        $rule = TaskAssignmentRule::create([
            'empresa_id' => $user->empresa_id,
            'task_template_id' => $firstTemplateId,
            'created_by' => $user->id,
            'assignee_type' => $data['assignee_type'],
            'assignee_id' => $data['assignee_id'] ?? null,
            'section_id' => $data['section_id'] ?? null,
            'day_of_week' => $data['day_of_week'],
            'trigger_time' => $data['trigger_time'] ?? null,
            'trigger_event' => $data['trigger_event'] ?? 'time',
            'is_active' => true,
        ]);

        // Crear items para cada template
        $sortOrder = 0;
        foreach ($data['template_ids'] as $templateId) {
            $sortOrder++;
            TaskAssignmentRuleItem::create([
                'empresa_id' => $user->empresa_id,
                'rule_id' => $rule->id,
                'template_id' => $templateId,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);
        }

        $rule->load(['items.template', 'section']);

        return response()->json(['item' => $rule], 201);
    }

    public function show(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('supervisor');

        $rule = TaskAssignmentRule::where('empresa_id', $u->empresa_id)
            ->where('id', $id)
            ->with(['items.template', 'section'])
            ->first();
        if (! $rule) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        return response()->json(['item' => $rule]);
    }

    public function update(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $rule = TaskAssignmentRule::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $rule) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $data = $request->validate([
            'assignee_type' => ['sometimes', Rule::in(['empleado', 'position', 'section_supervisor'])],
            'assignee_id' => ['sometimes', 'nullable', 'uuid'],
            'section_id' => ['sometimes', 'nullable', 'uuid', 'exists:sections,id'],
            'day_of_week' => ['sometimes', 'array'],
            'day_of_week.*' => ['integer', 'min:0', 'max:6'],
            'trigger_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'trigger_event' => ['sometimes', Rule::in(['time', 'attendance_checkin', 'both'])],
            'is_active' => ['sometimes', 'boolean'],
            'template_ids' => ['sometimes', 'array', 'min:1'],
            'template_ids.*' => ['uuid', 'exists:task_templates,id'],
        ]);

        if (isset($data['section_id'])) {
            $section = Section::where('empresa_id', $u->empresa_id)->where('id', $data['section_id'])->first();
            if (! $section) {
                return response()->json(['message' => 'Sección no encontrada'], 404);
            }
        }

        $rule->fill($data);

        if (! empty($data['template_ids'])) {
            // Validar templates
            $validTemplates = TaskTemplate::where('empresa_id', $u->empresa_id)
                ->whereIn('id', $data['template_ids'])
                ->pluck('id')
                ->all();

            if (count($validTemplates) !== count($data['template_ids'])) {
                return response()->json(['message' => 'Uno o más templates no válidos'], 422);
            }

            // Actualizar template principal
            $rule->task_template_id = $data['template_ids'][0];

            // Sincronizar items: eliminar existentes y recrear
            TaskAssignmentRuleItem::where('rule_id', $rule->id)->delete();

            $sortOrder = 0;
            foreach ($data['template_ids'] as $templateId) {
                $sortOrder++;
                TaskAssignmentRuleItem::create([
                    'empresa_id' => $u->empresa_id,
                    'rule_id' => $rule->id,
                    'template_id' => $templateId,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ]);
            }
        }

        $rule->save();
        $rule->load(['items.template', 'section']);

        return response()->json(['item' => $rule]);
    }

    public function destroy(Request $request, string $id)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $rule = TaskAssignmentRule::where('empresa_id', $u->empresa_id)->where('id', $id)->first();
        if (! $rule) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        // Eliminar items primero
        TaskAssignmentRuleItem::where('rule_id', $rule->id)->delete();
        $rule->delete();

        return response()->json(['message' => 'Eliminado']);
    }
}

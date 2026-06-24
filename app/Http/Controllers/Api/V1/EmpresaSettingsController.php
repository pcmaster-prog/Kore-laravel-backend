<?php
//EmpresaSettingsController: manejo de configuraciones específicas de empresa, como inicio de semana en calendario
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

use App\Models\Empresa;

class EmpresaSettingsController extends Controller
{
    // PATCH /empresa/settings/calendar (admin)
    public function updateCalendar(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'week_start' => ['required','integer', Rule::in([0,1,2,3,4,5,6])]
        ]);

        $empresa = Empresa::where('id', $u->empresa_id)->first();
        if (!$empresa) return response()->json(['message'=>'Empresa no encontrada'], 404);

        $settings = is_array($empresa->settings) ? $empresa->settings : [];
        $settings['calendar'] = $settings['calendar'] ?? [];
        $settings['calendar']['week_start'] = (int)$data['week_start'];

        $empresa->settings = $settings;
        $empresa->save();

        return response()->json([
            'message' => 'Calendar settings updated',
            'settings' => $empresa->settings,
        ]);
    }

    public function getOperativo(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $empresa = Empresa::where('id', $u->empresa_id)->first();
        if (!$empresa) return response()->json(['message'=>'Empresa no encontrada'], 404);

        $settings = is_array($empresa->settings) ? $empresa->settings : [];
        $defaultSchedule = [];
        for ($i = 0; $i <= 6; $i++) {
            $defaultSchedule[] = [
                'weekday' => $i,
                'check_in_time' => '09:00',
                'check_out_time' => '17:00',
                'is_working_day' => true,
            ];
        }

        $operativo = $settings['operativo'] ?? [
            'check_in_time'         => '09:00',
            'check_out_time'        => '17:00',
            'late_tolerance'        => 10,
            'max_hours'             => 8,
            'auto_close_enabled'    => false,
            'auto_close_time'       => '17:00',
            'auto_close_weekday'    => null,
            'meal_duration_minutes' => 30,
            'break_duration_minutes'=> 10,
            'break_pauses_clock'    => true,
            'week_schedule'         => $defaultSchedule,
        ];
        $calendar = $settings['calendar'] ?? [
            'week_start' => 0 // Domingo
        ];

        return response()->json([
            'operativo' => $operativo,
            'calendar' => $calendar
        ]);
    }

    // PUT /settings/schedule (admin) — alias de operativo/calendario con nombres del frontend
    public function updateSchedule(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'entry_time'        => ['sometimes', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'exit_time'         => ['sometimes', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'week_start_day'    => ['sometimes', 'integer', Rule::in([0,1,2,3,4,5,6])],
            'tolerance_minutes' => ['sometimes', 'integer', 'min:0'],
            'max_hours'         => ['sometimes', 'integer', 'min:1', 'max:24'],
            'auto_close_shift'  => ['sometimes', 'boolean'],
            'week_schedule'     => ['sometimes', 'array'],
            'week_schedule.*.weekday'        => ['required_with:week_schedule', 'integer', 'min:0', 'max:6'],
            'week_schedule.*.check_in_time'  => ['required_with:week_schedule', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'week_schedule.*.check_out_time' => ['required_with:week_schedule', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'week_schedule.*.is_working_day' => ['required_with:week_schedule', 'boolean'],
        ]);

        // Actualizar día de inicio de semana vía método existente
        if (isset($data['week_start_day'])) {
            $calendarRequest = $request->duplicate([], ['week_start' => $data['week_start_day']]);
            $calendarResponse = $this->updateCalendar($calendarRequest);
            if ($calendarResponse->getStatusCode() >= 400) {
                return $calendarResponse;
            }
        }

        // Mapear nombres del frontend a los campos internos de operativo
        $mapped = [];
        if (isset($data['entry_time'])) {
            $mapped['check_in_time'] = $data['entry_time'];
        }
        if (isset($data['exit_time'])) {
            $mapped['check_out_time'] = $data['exit_time'];
        }
        if (isset($data['tolerance_minutes'])) {
            $mapped['late_tolerance'] = $data['tolerance_minutes'];
        }
        if (isset($data['max_hours'])) {
            $mapped['max_hours'] = $data['max_hours'];
        }
        if (isset($data['auto_close_shift'])) {
            $mapped['auto_close_enabled'] = $data['auto_close_shift'];
        }
        if (isset($data['week_schedule'])) {
            $mapped['week_schedule'] = $data['week_schedule'];
        }

        if (empty($mapped) && !isset($data['week_start_day'])) {
            return response()->json(['message' => 'No se enviaron datos para actualizar.'], 422);
        }

        $operativoRequest = $request->duplicate([], $mapped);
        return $this->updateOperativo($operativoRequest);
    }

    public function updateOperativo(Request $request)
    {
        $u = $request->user();
        Gate::authorize('admin');

        $data = $request->validate([
            'check_in_time'         => ['sometimes', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'check_out_time'        => ['sometimes', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'late_tolerance'        => ['sometimes', 'integer', 'min:0'],
            'max_hours'             => ['sometimes', 'integer', 'min:1', 'max:24'],
            'auto_close_enabled'    => ['sometimes', 'boolean'],
            'auto_close_time'       => ['nullable', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'auto_close_weekday'    => ['nullable', 'integer', 'min:0', 'max:6'],
            'meal_duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'break_duration_minutes'=> ['sometimes', 'integer', 'min:1', 'max:60'],
            'break_pauses_clock'    => ['sometimes', 'boolean'],
            'week_schedule'         => ['sometimes', 'array'],
            'week_schedule.*.weekday'        => ['required_with:week_schedule', 'integer', 'min:0', 'max:6'],
            'week_schedule.*.check_in_time'  => ['required_with:week_schedule', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'week_schedule.*.check_out_time' => ['required_with:week_schedule', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'week_schedule.*.is_working_day' => ['required_with:week_schedule', 'boolean'],
        ]);

        $empresa = Empresa::where('id', $u->empresa_id)->first();
        if (!$empresa) return response()->json(['message'=>'Empresa no encontrada'], 404);

        $settings = is_array($empresa->settings) ? $empresa->settings : [];
        $oldOperativo = $settings['operativo'] ?? [];

        $newOperativo = [
            'check_in_time'          => $data['check_in_time']          ?? ($oldOperativo['check_in_time'] ?? '09:00'),
            'check_out_time'         => $data['check_out_time']         ?? ($oldOperativo['check_out_time'] ?? '17:00'),
            'late_tolerance'         => (int)($data['late_tolerance']   ?? ($oldOperativo['late_tolerance'] ?? 10)),
            'max_hours'              => (int)($data['max_hours']        ?? ($oldOperativo['max_hours'] ?? 8)),
            'auto_close_enabled'     => (bool)($data['auto_close_enabled'] ?? ($oldOperativo['auto_close_enabled'] ?? false)),
            'auto_close_time'        => $data['auto_close_time']        ?? ($oldOperativo['auto_close_time'] ?? null),
            'auto_close_weekday'     => isset($data['auto_close_weekday']) ? (int)$data['auto_close_weekday'] : ($oldOperativo['auto_close_weekday'] ?? null),
            'meal_duration_minutes'  => (int)($data['meal_duration_minutes']  ?? ($oldOperativo['meal_duration_minutes'] ?? 30)),
            'break_duration_minutes' => (int)($data['break_duration_minutes'] ?? ($oldOperativo['break_duration_minutes'] ?? 10)),
            'break_pauses_clock'     => (bool)($data['break_pauses_clock']     ?? ($oldOperativo['break_pauses_clock'] ?? true)),
        ];

        if (isset($data['week_schedule'])) {
            $newOperativo['week_schedule'] = array_map(fn ($item) => [
                'weekday'        => (int) $item['weekday'],
                'check_in_time'  => $item['check_in_time'],
                'check_out_time' => $item['check_out_time'],
                'is_working_day' => (bool) $item['is_working_day'],
            ], $data['week_schedule']);
        } elseif (isset($oldOperativo['week_schedule'])) {
            $newOperativo['week_schedule'] = $oldOperativo['week_schedule'];
        } else {
            $defaultSchedule = [];
            for ($i = 0; $i <= 6; $i++) {
                $defaultSchedule[] = [
                    'weekday' => $i,
                    'check_in_time' => $newOperativo['check_in_time'],
                    'check_out_time' => $newOperativo['check_out_time'],
                    'is_working_day' => true,
                ];
            }
            $newOperativo['week_schedule'] = $defaultSchedule;
        }

        $settings['operativo'] = $newOperativo;

        $empresa->settings = $settings;
        $empresa->save();

        return response()->json([
            'message' => 'Esquema operativo actualizado',
            'operativo' => $settings['operativo'],
        ]);
    }
}
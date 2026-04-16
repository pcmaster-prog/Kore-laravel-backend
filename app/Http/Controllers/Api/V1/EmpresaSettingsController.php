<?php
//EmpresaSettingsController: manejo de configuraciones específicas de empresa, como inicio de semana en calendario
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

use App\Models\Empresa;

class EmpresaSettingsController extends Controller
{
    // PATCH /empresa/settings/calendar (admin)
    public function updateCalendar(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

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
        if ($u->role !== 'admin') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $empresa = Empresa::where('id', $u->empresa_id)->first();
        if (!$empresa) return response()->json(['message'=>'Empresa no encontrada'], 404);

        $settings = is_array($empresa->settings) ? $empresa->settings : [];
        $operativo = $settings['operativo'] ?? [
            'check_in_time' => '08:00',
            'check_out_time' => '17:00',
            'late_tolerance' => 10,
            'max_hours' => 8
        ];
        $calendar = $settings['calendar'] ?? [
            'week_start' => 0 // Domingo
        ];

        return response()->json([
            'operativo' => $operativo,
            'calendar' => $calendar
        ]);
    }

    public function updateOperativo(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message'=>'No autorizado'], 403);
        }

        $data = $request->validate([
            'check_in_time'  => ['required', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'check_out_time' => ['required', 'string', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'late_tolerance' => ['required', 'integer', 'min:0'],
            'max_hours'      => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        $empresa = Empresa::where('id', $u->empresa_id)->first();
        if (!$empresa) return response()->json(['message'=>'Empresa no encontrada'], 404);

        $settings = is_array($empresa->settings) ? $empresa->settings : [];
        $settings['operativo'] = [
            'check_in_time' => $data['check_in_time'],
            'check_out_time' => $data['check_out_time'],
            'late_tolerance' => (int)$data['late_tolerance'],
            'max_hours' => (int)$data['max_hours']
        ];

        $empresa->settings = $settings;
        $empresa->save();

        return response()->json([
            'message' => 'Esquema operativo actualizado',
            'operativo' => $settings['operativo'],
        ]);
    }
}
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
}
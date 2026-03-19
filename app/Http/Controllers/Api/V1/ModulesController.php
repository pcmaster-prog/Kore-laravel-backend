<?php
//ModulesController: manejo de módulos habilitados por empresa (feature flags)
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Modulo;
use App\Models\EmpresaModulo;

class ModulesController extends Controller
{
    // Catálogo global (para admins o para UI interna)
    public function catalog()
    {
        $mods = Modulo::orderBy('is_premium')->orderBy('name')->get()
            ->map(fn($m)=>[
                'id'=>$m->id,
                'key'=>$m->key,
                'name'=>$m->name,
                'is_premium'=>$m->is_premium,
            ]);

        return response()->json(['items'=>$mods]);
    }

    // Módulos habilitados por empresa (feature flags)
    public function companyModules(Request $request)
    {
        $empresaId = $request->user()->empresa_id;

        // Queremos devolver: catálogo + enabled por empresa
        $catalog = Modulo::orderBy('is_premium')->orderBy('name')->get();

        $flags = EmpresaModulo::where('empresa_id', $empresaId)->get()
            ->keyBy('modulo_id');

        $items = $catalog->map(function ($m) use ($flags) {
            $flag = $flags->get($m->id);

            return [
                'key' => $m->key,
                'name' => $m->name,
                'is_premium' => (bool)$m->is_premium,
                'enabled' => $flag ? (bool)$flag->enabled : false,
                'settings' => $flag?->settings,
            ];
        });

        return response()->json(['items'=>$items]);
    }

    // Toggle de módulo por empresa (solo admin)
    public function toggle(Request $request, string $key)
    {
        $empresaId = $request->user()->empresa_id;

        $data = $request->validate([
            'enabled' => ['required','boolean'],
        ]);

        $mod = Modulo::where('key', $key)->first();
        if (!$mod) return response()->json(['message'=>'Módulo no existe'], 404);

        $flag = EmpresaModulo::firstOrCreate(
            ['empresa_id'=>$empresaId, 'modulo_id'=>$mod->id],
            ['enabled'=>false]
        );

        $flag->enabled = $data['enabled'];
        $flag->save();

        return response()->json([
            'message'=>'OK',
            'module'=>[
                'key'=>$mod->key,
                'enabled'=>$flag->enabled,
            ]
        ]);
    }
}

<?php
//EmpresaController: manejo de registro de empresa y usuario admin inicial, asignación de módulos base
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use App\Models\Empresa;
use App\Models\User;

class EmpresaController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required','string','max:120'],
            'admin_name'   => ['required','string','max:120'],
            'admin_email'  => ['required','email','max:150','unique:users,email'],
            'password'     => ['required','string','min:8'],
            'palette_key'  => ['nullable','string','max:50'],
        ]);

        $result = DB::transaction(function () use ($data) {

            $slug = Str::slug($data['company_name']).'-'.Str::lower(Str::random(6));

            $empresa = Empresa::create([
            'name' => $data['company_name'],
            'slug' => $slug,
            'palette_key' => $data['palette_key'] ?? null,
            'status' => 'active',
            'settings' => [
            'calendar' => [
            'week_start' => 0, // domingo por defecto
            ],
                ],
            ]);

            $user = User::create([
                'empresa_id' => $empresa->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['password']),
                'role' => 'admin',
                'is_active' => true,
            ]);

            // módulos base por defecto (usando los nuevos slugs localizados)
            $baseModules = ['tareas', 'asistencia', 'configuracion'];
            $modulesToInsert = [];
            foreach ($baseModules as $slug) {
                $modulesToInsert[] = [
                    'id'          => (string) Str::uuid(),
                    'empresa_id'  => $empresa->id,
                    'module_slug' => $slug,
                    'enabled'     => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }
            DB::table('empresa_modules')->insert($modulesToInsert);

            $token = $user->createToken('kore-api')->plainTextToken;

            return compact('empresa','user','token');
        });

        return response()->json([
            'token' => $result['token'],
            'empresa' => [
                'id'=>$result['empresa']->id,
                'name'=>$result['empresa']->name,
                'slug'=>$result['empresa']->slug,
                'palette_key'=>$result['empresa']->palette_key,
                'status'=>$result['empresa']->status,
            ],
            'user' => [
                'id'=>$result['user']->id,
                'name'=>$result['user']->name,
                'email'=>$result['user']->email,
                'role'=>$result['user']->role,
            ],
        ], 201);
    }

    public function modulos(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }
        
        $modulos = DB::table('empresa_modules')
            ->where('empresa_id', $u->empresa_id)
            ->get(['module_slug as slug', 'enabled'])
            ->map(function ($item) {
                return [
                    'slug' => $item->slug,
                    'enabled' => (bool) $item->enabled
                ];
            })
            ->values()
            ->all();

        return response()->json($modulos);
    }

    public function toggleModulo(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'module_slug' => ['required', 'string'],
            'enabled'     => ['required', 'boolean'],
        ]);

        $empresaId = $u->empresa_id;

        $exists = DB::table('empresa_modules')
            ->where('empresa_id', $empresaId)
            ->where('module_slug', $data['module_slug'])
            ->first();

        if ($exists) {
            DB::table('empresa_modules')
                ->where('id', $exists->id)
                ->update(['enabled' => $data['enabled'], 'updated_at' => now()]);
        } else {
            DB::table('empresa_modules')->insert([
                'id' => (string) Str::uuid(),
                'empresa_id' => $empresaId,
                'module_slug' => $data['module_slug'],
                'enabled' => $data['enabled'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Módulo actualizado']);
    }

    public function config(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'allowed_ip' => ['nullable', 'string', 'max:45']
        ]);

        $empresa = Empresa::find($u->empresa_id);
        if ($empresa) {
            $empresa->allowed_ip = $data['allowed_ip'];
            $empresa->save();
        }

        return response()->json(['message' => 'Configuración actualizada', 'empresa' => $empresa]);
    }

    public function getRed(Request $request)
    {
        $u = $request->user();
        $empresa = Empresa::find($u->empresa_id);

        return response()->json([
            'allowed_ip' => $empresa ? $empresa->allowed_ip : null
        ]);
    }

    public function updateRed(Request $request)
    {
        $u = $request->user();
        if ($u->role !== 'admin') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'allowed_ip' => ['nullable', 'string', 'max:45']
        ]);

        $empresa = Empresa::find($u->empresa_id);
        if ($empresa) {
            $empresa->allowed_ip = $data['allowed_ip'];
            $empresa->save();
        }

        return response()->json([
            'message' => 'Configuración de red actualizada',
            'allowed_ip' => $empresa->allowed_ip
        ]);
    }
}

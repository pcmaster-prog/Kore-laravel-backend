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
use App\Models\Modulo;
use App\Models\EmpresaModulo;

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

            // módulos base por defecto
            $baseKeys = ['employees','attendance','tasks','evidences','reports_basic'];

            $mods = Modulo::whereIn('key', $baseKeys)->get();
            foreach ($mods as $m) {
                EmpresaModulo::create([
                    'empresa_id' => $empresa->id,
                    'modulo_id' => $m->id,
                    'enabled' => true,
                    'settings' => null,
                ]);
            }

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
}

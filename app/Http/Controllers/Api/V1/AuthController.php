<?php
//AuthController: manejo de autenticación, generación de token, endpoint para obtener datos de usuario autenticado y empresa asociada
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !$user->is_active || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message'=>'Credenciales inválidas'], 401);
        }

        $token = $user->createToken('kore-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id'=>$user->id,
                'name'=>$user->name,
                'email'=>$user->email,
                'role'=>$user->role,
                'empresa_id'=>$user->empresa_id,
            ],
        ]);
    }

    public function me(Request $request)
{
    $u = $request->user();

    $empresa = null;
    $enabledKeys = [];

    if ($u->empresa_id) {
        $empresa = \App\Models\Empresa::find($u->empresa_id);

        $enabledKeys = \App\Models\EmpresaModulo::where('empresa_id', $u->empresa_id)
            ->where('enabled', true)
            ->join('modulos', 'empresa_modulos.modulo_id', '=', 'modulos.id')
            ->pluck('modulos.key')
            ->values()
            ->all();
    }

    return response()->json([
        'user' => [
            'id'=>$u->id,
            'name'=>$u->name,
            'email'=>$u->email,
            'role'=>$u->role,
            'empresa_id'=>$u->empresa_id,
        ],
        'empresa' => $empresa ? [
            'id'=>$empresa->id,
            'name'=>$empresa->name,
            'slug'=>$empresa->slug,
            'palette_key'=>$empresa->palette_key,
            'status'=>$empresa->status,
        ] : null,
        'features' => [
            'enabled_modules' => $enabledKeys
        ]
    ]);
        }


    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message'=>'Logout OK']);
    }
}

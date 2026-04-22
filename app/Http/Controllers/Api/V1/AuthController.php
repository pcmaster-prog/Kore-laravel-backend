<?php
//AuthController: manejo de autenticación, generación de token, endpoint para obtener datos de usuario autenticado y empresa asociada
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Timing-attack mitigation: always run Hash::check even if user doesn't exist
        if (!$user) {
            Hash::check($data['password'], '$2y$12$dummyhashvaluetopreventtimingattacksx');
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if (!$user->is_active || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
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

        $enabledKeys = DB::table('empresa_modules')
            ->where('empresa_id', $u->empresa_id)
            ->where('enabled', true)
            ->pluck('module_slug')
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

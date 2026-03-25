<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Empresa;
use App\Models\User;
use App\Models\Empleado;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'empresa_nombre'       => ['required', 'string', 'max:160'],
            'industry'             => ['nullable', 'string', 'max:100'],
            'employee_count_range' => ['nullable', 'string', 'max:20'],
            'admin_name'           => ['required', 'string', 'max:160'],
            'admin_email'          => ['required', 'email', 'max:200', 'unique:users,email'],
            'admin_password'       => ['required', 'string', 'min:6', 'max:100'],
            'modules'              => ['nullable', 'array'],
            'modules.*'            => ['string']
        ]);

        DB::beginTransaction();
        try {
            $slug = Str::slug($data['empresa_nombre']).'-'.Str::lower(Str::random(6));

            // 1. Crear empresa
            $empresa = Empresa::create([
                'name'                 => $data['empresa_nombre'],
                'slug'                 => $slug,
                'status'               => 'active',
                'plan'                 => 'starter',
                'industry'             => $data['industry'] ?? null,
                'employee_count_range' => $data['employee_count_range'] ?? null,
                'settings'             => [
                    'calendar' => ['week_start' => 0]
                ]
            ]);

            // 2. Crear admin user
            $user = User::create([
                'empresa_id' => $empresa->id,
                'name'       => $data['admin_name'],
                'email'      => $data['admin_email'],
                'password'   => Hash::make($data['admin_password']),
                'role'       => 'admin',
                'is_active'  => true,
            ]);

            // 3. Crear empleado
            $empleado = Empleado::create([
                'empresa_id'     => $empresa->id,
                'user_id'        => $user->id,
                'full_name'      => $data['admin_name'],
                'status'         => 'active',
                'payment_type'   => 'hourly',
            ]);

            // 4. Modulos
            $selectedModules = $data['modules'] ?? [];
            $defaultModules = ['tareas', 'asistencia', 'nomina', 'configuracion', 'gondolas'];
            
            $allModules = array_unique(array_merge($selectedModules, $defaultModules));

            $modulesToInsert = [];
            foreach ($allModules as $mod) {
                $modulesToInsert[] = [
                    'id'          => (string) Str::uuid(),
                    'empresa_id'  => $empresa->id,
                    'module_slug' => $mod,
                    'enabled'     => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }

            DB::table('empresa_modules')->insert($modulesToInsert);

            DB::commit();

            // 5. Generate Token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'token'   => $token,
                'user'    => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                ],
                'empresa' => [
                    'id'                   => $empresa->id,
                    'name'                 => $empresa->name,
                    'plan'                 => $empresa->plan,
                    'industry'             => $empresa->industry,
                    'employee_count_range' => $empresa->employee_count_range
                ]
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error en el registro', 'detail' => $e->getMessage()], 500);
        }
    }
}

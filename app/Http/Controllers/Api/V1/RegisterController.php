<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $slug = Str::slug($data['empresa_nombre']).'-'.Str::lower(Str::random(6));

            // 1. Crear empresa
            $empresa = Empresa::create([
                'name' => $data['empresa_nombre'],
                'slug' => $slug,
                'status' => 'active',
                'plan' => 'starter',
                'industry' => $data['industry'] ?? null,
                'employee_count_range' => $data['employee_count_range'] ?? null,
                'settings' => [
                    'calendar' => ['week_start' => 0],
                ],
            ]);

            // 2. Crear admin user
            $user = User::create([
                'empresa_id' => $empresa->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role' => 'admin',
                'is_active' => true,
            ]);

            // 3. Crear empleado
            $empleado = Empleado::create([
                'empresa_id' => $empresa->id,
                'user_id' => $user->id,
                'full_name' => $data['admin_name'],
                'status' => 'active',
                'payment_type' => 'hourly',
            ]);

            // 4. Modulos
            $selectedModules = $data['modules'] ?? [];
            $defaultModules = ['tareas', 'asistencia', 'nomina', 'configuracion', 'gondolas', 'semaforo'];

            $allModules = array_unique(array_merge($selectedModules, $defaultModules));

            $modulesToInsert = [];
            foreach ($allModules as $mod) {
                $modulesToInsert[] = [
                    'id' => (string) Str::uuid(),
                    'empresa_id' => $empresa->id,
                    'module_slug' => $mod,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('empresa_modules')->insert($modulesToInsert);

            DB::commit();

            // 5. Enviar correo de verificación antes de permitir el acceso.
            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'Registro exitoso. Revisa tu correo electrónico para activar tu cuenta.',
                'user' => new UserResource($user),
                'empresa' => [
                    'id' => $empresa->id,
                    'name' => $empresa->name,
                    'plan' => $empresa->plan,
                    'industry' => $empresa->industry,
                    'employee_count_range' => $empresa->employee_count_range,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error en el registro: '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['message' => 'Error en el registro. Inténtalo de nuevo más tarde.'], 500);
        }
    }
}

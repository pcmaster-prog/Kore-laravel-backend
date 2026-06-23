<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\Empleado;
use App\Models\EmpleadoModulo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeOnboardingService
{
    /**
     * Crea un nuevo empleado a partir de una aplicación ATS contratada.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Application $app, User $admin, array $data): Empleado
    {
        return DB::transaction(function () use ($app, $admin, $data) {
            $app->update(['status' => 'hired']);

            ApplicationStatusLog::create([
                'application_id' => $app->id,
                'from_status' => $app->getOriginal('status'),
                'to_status' => 'hired',
                'changed_by' => $admin->id,
                'notes' => $data['notes'] ?? "Contratado a prueba por {$data['trial_months']} meses.",
            ]);

            $aspiranteUser = $app->user;
            $aspiranteUser->update([
                'role' => 'empleado_prueba',
                'empresa_id' => $admin->empresa_id,
            ]);

            $empleado = Empleado::create([
                'empresa_id' => $app->empresa_id,
                'user_id' => $aspiranteUser->id,
                'full_name' => $aspiranteUser->name,
                'status' => 'active',
                'hired_at' => now(),
                'payment_type' => 'daily',
                'daily_rate' => $data['salary'],
                'position_id' => $data['position_id'] ?? null,
            ]);

            $this->assignModules($empleado, $data['modules'] ?? []);

            return $empleado;
        });
    }

    /**
     * Recontrata a un ex-empleado existente a partir de una aplicación ATS.
     *
     * @param  array<string, mixed>  $data
     */
    public function rehire(Application $app, User $admin, Empleado $previousEmpleado, array $data): Empleado
    {
        return DB::transaction(function () use ($app, $admin, $previousEmpleado, $data) {
            $app->update(['status' => 'hired']);

            ApplicationStatusLog::create([
                'application_id' => $app->id,
                'from_status' => $app->getOriginal('status'),
                'to_status' => 'hired',
                'changed_by' => $admin->id,
                'notes' => $data['notes'] ?? 'Recontratación rápida de ex-empleado.',
            ]);

            $aspiranteUser = $app->user;
            $aspiranteUser->update([
                'role' => 'empleado_prueba',
                'empresa_id' => $admin->empresa_id,
            ]);

            $previousEmpleado->restore();
            $previousEmpleado->update([
                'status' => 'active',
                'hired_at' => now(),
                'daily_rate' => $data['salary'],
                'position_id' => $data['position_id'] ?? $previousEmpleado->position_id,
            ]);

            $this->assignModules($previousEmpleado, $data['modules'] ?? []);

            return $previousEmpleado;
        });
    }

    /**
     * Busca el empleado anterior más reciente para un email en una empresa.
     */
    public function findPreviousEmployee(Application $app): ?Empleado
    {
        if (! $app->user?->email) {
            return null;
        }

        return Empleado::withTrashed()
            ->where('empresa_id', $app->empresa_id)
            ->whereHas('user', fn ($q) => $q->where('email', $app->user->email))
            ->latest()
            ->first();
    }

    /**
     * Asigna módulos individuales a un empleado.
     *
     * @param  array<int, string>  $modules
     */
    private function assignModules(Empleado $empleado, array $modules): void
    {
        if (empty($modules)) {
            return;
        }

        foreach ($modules as $moduleSlug) {
            EmpleadoModulo::updateOrCreate([
                'empleado_id' => $empleado->id,
                'module_slug' => $moduleSlug,
            ]);
        }
    }
}

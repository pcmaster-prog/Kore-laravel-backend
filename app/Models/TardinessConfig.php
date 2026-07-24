<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TardinessConfig extends Model
{
    use HasUuids;

    protected $table = 'tardiness_configs';

    protected $fillable = [
        'empresa_id',
        'grace_period_minutes',
        'late_threshold_minutes',
        'lates_to_absence',
        'accumulation_period',
        'penalize_rest_day',
        'notify_employee_on_late',
        'notify_manager_on_late',
    ];

    protected $casts = [
        'grace_period_minutes' => 'integer',
        'late_threshold_minutes' => 'integer',
        'lates_to_absence' => 'integer',
        'penalize_rest_day' => 'boolean',
        'notify_employee_on_late' => 'boolean',
        'notify_manager_on_late' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    /**
     * Única fuente de defaults. Antes había 7 firstOrCreate dispersos con
     * valores contradictorios (late_threshold_minutes 60 vs 1) y ganaba el
     * primero en ejecutarse — en la práctica myToday, dejando la ventana de
     * bloqueo en 1 minuto.
     *
     * Semántica: sin retardo hasta entrada + grace_period_minutes; con
     * retardo (sin autorización) hasta entrada + grace + late_threshold;
     * después, bloqueado salvo oportunidad aprobada.
     */
    public static function forEmpresa(string $empresaId): self
    {
        return static::firstOrCreate(
            ['empresa_id' => $empresaId],
            [
                'grace_period_minutes' => 5,
                'late_threshold_minutes' => 60,
                'lates_to_absence' => 3,
                'accumulation_period' => 'month',
                'penalize_rest_day' => true,
                'notify_employee_on_late' => true,
                'notify_manager_on_late' => true,
            ]
        );
    }
}

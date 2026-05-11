<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

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
        'grace_period_minutes'    => 'integer',
        'late_threshold_minutes'  => 'integer',
        'lates_to_absence'        => 'integer',
        'penalize_rest_day'       => 'boolean',
        'notify_employee_on_late' => 'boolean',
        'notify_manager_on_late'  => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}

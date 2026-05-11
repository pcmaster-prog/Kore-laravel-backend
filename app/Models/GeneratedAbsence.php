<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GeneratedAbsence extends Model
{
    use HasUuids;

    protected $table = 'generated_absences';

    // Only created_at, no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'empleado_id',
        'empresa_id',
        'period_key',
        'type',
        'affects_rest_day_payment',
        'note',
    ];

    protected $casts = [
        'affects_rest_day_payment' => 'boolean',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}

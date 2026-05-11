<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeEvaluation extends Model
{
    use HasUuids;

    protected $table = 'employee_evaluations';

    protected $fillable = [
        'empresa_id', 'empleado_id', 'activated_by',
        'is_active', 'activated_at', 'deactivated_at',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'activated_at'    => 'datetime',
        'deactivated_at'  => 'datetime',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function activatedBy()
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function evaluaciones()
    {
        return $this->hasMany(DesempenoEvaluacion::class);
    }

    public function peerEvaluaciones()
    {
        return $this->hasMany(DesempenoPeerEvaluacion::class);
    }
}

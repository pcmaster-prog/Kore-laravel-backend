<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DesempenoEvaluacion extends Model
{
    use HasUuids;

    protected $table = 'desempeno_evaluaciones';

    protected $fillable = [
        'empresa_id', 'employee_evaluation_id', 'evaluador_id', 'evaluado_id',
        'evaluador_rol',
        'puntualidad', 'responsabilidad', 'actitud_trabajo', 'orden_limpieza',
        'atencion_cliente', 'trabajo_equipo', 'iniciativa', 'aprendizaje_adaptacion',
        'acciones', 'observaciones',
    ];

    protected $casts = [
        'acciones' => 'array',
    ];

    public function evaluador()
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    public function evaluado()
    {
        return $this->belongsTo(Empleado::class, 'evaluado_id');
    }

    // Calcula el total sobre 40
    public function getTotalAttribute(): int
    {
        return $this->puntualidad + $this->responsabilidad + $this->actitud_trabajo
             + $this->orden_limpieza + $this->atencion_cliente + $this->trabajo_equipo
             + $this->iniciativa + $this->aprendizaje_adaptacion;
    }

    // Calcula el porcentaje sobre 100
    public function getPorcentajeAttribute(): float
    {
        return round(($this->total / 40) * 100, 1);
    }
}

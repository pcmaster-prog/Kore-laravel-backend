<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DesempenoPeerEvaluacion extends Model
{
    use HasUuids;

    protected $table = 'desempeno_peer_evaluaciones';

    protected $fillable = [
        'empresa_id', 'employee_evaluation_id', 'evaluador_id', 'evaluado_id',
        'colaboracion', 'puntualidad', 'actitud', 'comunicacion',
    ];

    public function evaluador()
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    // Promedio sobre 5
    public function getPromedioAttribute(): float
    {
        return round(($this->colaboracion + $this->puntualidad
                    + $this->actitud + $this->comunicacion) / 4, 2);
    }

    // Porcentaje sobre 100
    public function getPorcentajeAttribute(): float
    {
        return round($this->promedio * 20, 1);
    }
}

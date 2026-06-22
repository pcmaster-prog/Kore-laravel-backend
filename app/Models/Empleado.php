<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empleado extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'empresa_id',
        'user_id',
        'position_id',
        'full_name',
        'employee_code',
        'position_title',
        'status',
        'hired_at',
        'daily_hours',
        'rest_weekday',
        'check_in_time',
        'payment_type',
        'hourly_rate',
        'daily_rate',
        'rfc',
        'nss',
        'curp',
        'expediente_url',
    ];

    protected $casts = [
        'hired_at' => 'date',
        'check_in_time' => 'datetime:H:i',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function empresa()
    {
        return $this->belongsTo(\App\Models\Empresa::class, 'empresa_id');
    }

    public function evaluations()
    {
        return $this->hasMany(EmployeeEvaluation::class, 'empleado_id');
    }

    public function position()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function sections()
    {
        return $this->belongsToMany(Section::class, 'empleado_sections', 'empleado_id', 'section_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }
    public function modulosIndividuales()
    {
        return $this->hasMany(EmpleadoModulo::class, 'empleado_id');
    }

    public function getModulosEfectivosAttribute()
    {
        // Get inherited from position
        $inherited = $this->position ? $this->position->modules->pluck('module_slug')->toArray() : [];
        
        // Get individual exceptions
        $individual = $this->modulosIndividuales->pluck('module_slug')->toArray();
        
        return array_values(array_unique(array_merge($inherited, $individual)));
    }
}

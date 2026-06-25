<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'area_id',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    public function taskTemplates()
    {
        return $this->hasMany(TaskTemplate::class, 'section_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'section_id');
    }

    public function supervisors()
    {
        return $this->belongsToMany(User::class, 'supervisor_sections', 'section_id', 'supervisor_user_id')
            ->withTimestamps();
    }

    public function empleados()
    {
        return $this->belongsToMany(Empleado::class, 'empleado_sections', 'section_id', 'empleado_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }
}

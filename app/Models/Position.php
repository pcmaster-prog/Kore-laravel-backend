<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'position_id');
    }

    public function baseTasks()
    {
        return $this->belongsToMany(TaskTemplate::class, 'position_tasks', 'position_id', 'task_template_id')
            ->withPivot('is_required', 'sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function modules()
    {
        return $this->hasMany(ModulePosition::class, 'position_id');
    }
}

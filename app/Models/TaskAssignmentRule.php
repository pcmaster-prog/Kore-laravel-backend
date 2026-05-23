<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TaskAssignmentRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'task_template_id',
        'created_by',
        'assignee_type',
        'assignee_id',
        'section_id',
        'day_of_week',
        'trigger_time',
        'trigger_event',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'array',
        'trigger_time' => 'datetime:H:i',
        'is_active' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function taskTemplate()
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'assignee_id');
    }

    public function position()
    {
        return $this->belongsTo(Position::class, 'assignee_id');
    }

    public function items()
    {
        return $this->hasMany(TaskAssignmentRuleItem::class, 'rule_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with('template');
    }

    public function allTemplates()
    {
        return $this->items->pluck('template_id')->all();
    }
}

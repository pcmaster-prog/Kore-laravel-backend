<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TaskAssignmentRuleItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'rule_id',
        'template_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function rule()
    {
        return $this->belongsTo(TaskAssignmentRule::class, 'rule_id');
    }

    public function template()
    {
        return $this->belongsTo(TaskTemplate::class, 'template_id');
    }
}

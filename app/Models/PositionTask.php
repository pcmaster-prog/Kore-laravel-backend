<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PositionTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'position_id',
        'task_template_id',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function position()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function taskTemplate()
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }
}

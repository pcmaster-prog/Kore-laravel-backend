<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RoutineSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'routine_id',
        'created_by',
        'trigger_time',
        'trigger_days',
        'auto_assign',
        'notify_push',
        'is_active',
    ];

    protected $casts = [
        'trigger_days' => 'array',
        'trigger_time' => 'datetime:H:i',
        'auto_assign' => 'boolean',
        'notify_push' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function routine()
    {
        return $this->belongsTo(TaskRoutine::class, 'routine_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

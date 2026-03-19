<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TaskRoutine extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'created_by',
        'name',
        'description',
        'recurrence',
        'weekdays',
        'start_date',
        'end_date',
        'is_active'
    ];

    protected $casts = [
        'weekdays' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(TaskRoutineItem::class, 'routine_id');
    }
}
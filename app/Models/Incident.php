<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'task_id',
        'task_assignee_id',
        'reported_by',
        'resolved_by',
        'type',
        'description',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function taskAssignee()
    {
        return $this->belongsTo(TaskAssignee::class, 'task_assignee_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

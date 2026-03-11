<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Task extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id','created_by','title','description','priority','status','due_at','meta'
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'meta' => 'array',
    ];

    public function assignees()
    {
        return $this->hasMany(TaskAssignee::class, 'task_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'empresa_id','created_by','title','description','priority','status','due_at','meta'
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'meta' => 'array',
    ];

    protected $appends = ['assignee_name', 'has_evidence'];

    public function getAssigneeNameAttribute()
    {
        if (!$this->relationLoaded('assignees')) {
            $a = $this->assignees()->with('empleado')->get();
        } else {
            $a = $this->assignees;
        }

        if ($a->isEmpty()) {
            return null;
        }

        $names = $a->map(fn($item) => $item->empleado?->full_name)
                  ->filter()
                  ->implode(', ');

        return $names ?: null;
    }

    public function getHasEvidenceAttribute()
    {
        if (array_key_exists('has_evidence', $this->attributes)) {
            return (bool) $this->attributes['has_evidence'];
        }
        return $this->evidences()->exists();
    }

    public function evidences()
    {
        return $this->hasMany(Evidence::class, 'task_id');
    }

    public function assignees()
    {
        return $this->hasMany(TaskAssignee::class, 'task_id');
    }
}

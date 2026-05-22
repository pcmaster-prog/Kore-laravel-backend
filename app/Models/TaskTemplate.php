<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TaskTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id','created_by','title','description','instructions',
        'estimated_minutes','priority','section','department','is_active','show_in_dashboard','tags','meta',
        'area_id','section_id','voice_note_enabled'
    ];

    protected $casts = [
        'instructions' => 'array',
        'tags' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean',
        'show_in_dashboard' => 'boolean',
        'voice_note_enabled' => 'boolean',
    ];

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function positions()
    {
        return $this->belongsToMany(Position::class, 'position_tasks', 'task_template_id', 'position_id')
            ->withPivot('is_required', 'sort_order')
            ->withTimestamps();
    }
}

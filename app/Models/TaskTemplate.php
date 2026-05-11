<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TaskTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id','created_by','title','description','instructions',
        'estimated_minutes','priority','is_active','show_in_dashboard','tags','meta'
    ];

    protected $casts = [
        'instructions' => 'array',
        'tags' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean',
        'show_in_dashboard' => 'boolean',
    ];
}

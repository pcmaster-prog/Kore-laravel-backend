<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TaskRoutineItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id','routine_id','template_id','sort_order','is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}

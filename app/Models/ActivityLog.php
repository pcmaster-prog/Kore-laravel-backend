<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id', 'user_id', 'empleado_id',
        'action', 'entity_type', 'entity_id',
        'meta', 'ip', 'user_agent',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}

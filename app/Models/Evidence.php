<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Evidence extends Model
{
    use HasUuids;

    protected $table = 'evidences'; 

    protected $fillable = [
        'empresa_id','uploaded_by','empleado_id',
        'task_id','task_assignee_id',
        'disk','path','original_name','mime','size','meta'
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}


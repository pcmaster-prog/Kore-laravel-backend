<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmployeeCalendarOverride extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id','empleado_id','date','type','is_paid','paid_minutes','note'
    ];

    protected $casts = [
        'date' => 'date',
        'is_paid' => 'boolean',
    ];
}

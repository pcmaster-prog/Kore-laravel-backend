<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Empleado extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'user_id',
        'full_name',
        'employee_code',
        'position_title',
        'status',
        'hired_at',
        'rfc',
        'nss',
        'expediente_url',
    ];

    protected $casts = [
        'hired_at' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}

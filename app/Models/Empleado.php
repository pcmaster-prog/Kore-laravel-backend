<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empleado extends Model
{
    use HasUuids, SoftDeletes;

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

    public function empresa()
    {
        return $this->belongsTo(\App\Models\Empresa::class, 'empresa_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Empresa extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'palette_key',
        'plan_id',
        'settings', 
        'plan',
        'logo_url',
        'industry',
        'employee_count_range',
        'allowed_ip',
    ];

    protected $casts = [
        'settings' => 'array', 
    ];

    public function modulos()
    {
        return $this->belongsToMany(Modulo::class, 'empresa_modulos')
            ->withPivot(['enabled','settings'])
            ->withTimestamps();
    }
}
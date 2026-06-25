<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmpresaModulo extends Model
{
    use HasUuids;

    protected $table = 'empresa_modulos';

    protected $fillable = ['empresa_id', 'modulo_id', 'enabled', 'settings'];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
    ];
}

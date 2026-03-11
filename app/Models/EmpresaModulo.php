<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmpresaModulo extends Model
{
    use HasUuids;

    protected $table = 'empresa_modulos';

    protected $fillable = ['empresa_id','modulo_id','enabled','settings'];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
    ];
}

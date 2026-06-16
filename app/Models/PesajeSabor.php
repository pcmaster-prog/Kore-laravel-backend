<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PesajeSabor extends Model
{
    protected $fillable = [
        'nombre',
        'presentacion',
        'activo',
    ];
}

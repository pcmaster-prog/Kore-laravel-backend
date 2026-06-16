<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaderasCatalogo extends Model
{
    protected $fillable = [
        'nombre',
        'tipo',
        'unidad_medida',
    ];
}

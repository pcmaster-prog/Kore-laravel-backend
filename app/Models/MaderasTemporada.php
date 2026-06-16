<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaderasTemporada extends Model
{
    protected $fillable = [
        'nombre',
        'mes_inicio',
        'mes_fin',
        'multiplicador',
    ];
}

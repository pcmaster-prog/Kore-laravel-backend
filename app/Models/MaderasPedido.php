<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaderasPedido extends Model
{
    protected $fillable = [
        'codigo',
        'cliente',
        'total_unidades',
        'status',
        'fecha_entrega',
    ];
}

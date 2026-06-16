<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaderasPedido extends Model
{
    protected $fillable = [
        'codigo',
        'cliente',
        'total_unidades',
        'total_precio',
        'items',
        'status',
        'fecha_entrega',
    ];

    protected $casts = [
        'items' => 'array',
        'total_precio' => 'decimal:2',
    ];
}

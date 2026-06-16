<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaderasInventario extends Model
{
    protected $fillable = [
        'catalogo_id',
        'stock',
        'stock_minimo',
        'status',
    ];

    public function catalogo()
    {
        return $this->belongsTo(MaderasCatalogo::class, 'catalogo_id');
    }
}

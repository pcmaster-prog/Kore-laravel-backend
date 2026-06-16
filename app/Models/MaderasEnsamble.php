<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaderasEnsamble extends Model
{
    protected $fillable = [
        'catalogo_id',
        'cantidad_generada',
        'status',
    ];

    public function catalogo()
    {
        return $this->belongsTo(MaderasCatalogo::class, 'catalogo_id');
    }

    public function piezas()
    {
        return $this->hasMany(MaderasEnsamblePieza::class, 'ensamble_id');
    }
}

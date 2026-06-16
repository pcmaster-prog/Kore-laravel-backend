<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaderasEnsamblePieza extends Model
{
    protected $fillable = [
        'ensamble_id',
        'catalogo_id',
        'cantidad_usada',
    ];

    public function ensamble()
    {
        return $this->belongsTo(MaderasEnsamble::class, 'ensamble_id');
    }

    public function catalogo()
    {
        return $this->belongsTo(MaderasCatalogo::class, 'catalogo_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaderasProduccion extends Model
{
    protected $fillable = [
        'empleado_id',
        'catalogo_id',
        'maquina',
        'cantidad',
        'fecha_registro',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function catalogo()
    {
        return $this->belongsTo(MaderasCatalogo::class, 'catalogo_id');
    }
}

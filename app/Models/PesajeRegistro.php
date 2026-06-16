<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PesajeRegistro extends Model
{
    protected $fillable = [
        'empleado_id',
        'sabor_id',
        'peso',
        'fecha_registro',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function sabor()
    {
        return $this->belongsTo(PesajeSabor::class);
    }
}

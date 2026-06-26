<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PesajeRegistro extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'empresa_id',
        'empleado_id',
        'sabor_id',
        'cantidad',
        'peso',
        'fecha_registro',
    ];

    protected $casts = [
        'cantidad' => 'float',
        'peso' => 'float',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function sabor()
    {
        return $this->belongsTo(PesajeSabor::class);
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PesajeSabor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'empresa_id',
        'nombre',
        'presentacion',
        'peso_estandar',
        'unidad',
        'activo',
    ];

    protected $casts = [
        'peso_estandar' => 'float',
        'activo' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}

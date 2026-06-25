<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoModulo extends Model
{
    protected $table = 'empleado_modulos';

    protected $fillable = ['empleado_id', 'module_slug'];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }
}

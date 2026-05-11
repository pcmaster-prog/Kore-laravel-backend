<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Gondola extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id', 'nombre', 'descripcion', 'ubicacion', 'orden', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function productos()
    {
        return $this->hasMany(GondolaProducto::class)
            ->where('activo', true)
            ->orderBy('orden');
    }

    public function ordenes()
    {
        return $this->hasMany(GondolaOrden::class);
    }

    public function ultimaOrden()
    {
        return $this->hasOne(GondolaOrden::class)->latest();
    }
}

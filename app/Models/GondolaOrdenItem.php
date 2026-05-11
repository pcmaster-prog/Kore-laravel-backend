<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GondolaOrdenItem extends Model
{
    use HasUuids;

    protected $table = 'gondola_orden_items';

    protected $fillable = [
        'empresa_id', 'orden_id', 'gondola_producto_id',
        'clave', 'nombre', 'unidad', 'cantidad',
    ];

    protected $casts = [
        'cantidad' => 'float',
    ];

    public function producto()
    {
        return $this->belongsTo(GondolaProducto::class, 'gondola_producto_id');
    }
}

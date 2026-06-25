<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GondolaOrdenItem extends Model
{
    use HasUuids;

    protected $table = 'gondola_orden_items';

    protected $fillable = [
        'empresa_id', 'orden_id', 'gondola_producto_id', 'product_id',
        'clave', 'nombre', 'unidad', 'unit', 'cantidad',
    ];

    protected $casts = [
        'cantidad' => 'float',
    ];

    public function producto()
    {
        return $this->belongsTo(GondolaProducto::class, 'gondola_producto_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

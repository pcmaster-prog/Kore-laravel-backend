<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'sku',
        'name',
        'description',
        'default_unit',
        'photo_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function locations()
    {
        return $this->hasMany(GondolaProducto::class, 'product_id')
            ->where('activo', true)
            ->with('gondola');
    }

    public function gondolaProductos()
    {
        return $this->hasMany(GondolaProducto::class, 'product_id');
    }
}

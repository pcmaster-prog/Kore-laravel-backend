<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GondolaProducto extends Model
{
    use HasUuids;

    protected $table = 'gondola_productos';

    protected $fillable = [
        'empresa_id', 'gondola_id', 'clave', 'nombre',
        'descripcion', 'unidad', 'foto_url', 'orden', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function gondola()
    {
        return $this->belongsTo(Gondola::class);
    }
}

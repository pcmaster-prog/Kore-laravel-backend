<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SemaforoConfig extends Model
{
    protected $table = 'semaforo_configs';

    protected $fillable = [
        'empresa_id',
        'created_by',
        'criterios_admin',
        'criterios_peer',
        'peso_admin',
        'peso_peer',
        'umbral_verde',
        'umbral_amarillo',
    ];

    protected $casts = [
        'criterios_admin' => 'array',
        'criterios_peer' => 'array',
        'peso_admin' => 'integer',
        'peso_peer' => 'integer',
        'umbral_verde' => 'integer',
        'umbral_amarillo' => 'integer',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

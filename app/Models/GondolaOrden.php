<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GondolaOrden extends Model
{
    use HasUuids;

    protected $table = 'gondola_ordenes';

    protected $fillable = [
        'empresa_id', 'gondola_id', 'empleado_id', 'status',
        'evidencia_url', 'notas_empleado', 'notas_rechazo',
        'approved_by', 'completed_at', 'approved_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    public function gondola()
    {
        return $this->belongsTo(Gondola::class);
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function items()
    {
        return $this->hasMany(GondolaOrdenItem::class, 'orden_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

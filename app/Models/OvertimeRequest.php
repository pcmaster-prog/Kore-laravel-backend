<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class OvertimeRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'empleado_id',
        'fecha',
        'motivo',
        'minutos_solicitados',
        'status',
        'reviewed_by',
        'reviewer_note',
        'reviewed_at',
    ];

    protected $casts = [
        'fecha'       => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LateArrivalRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'empleado_id',
        'user_id',
        'date',
        'motivo',
        'status',
        'reviewed_by',
        'reviewed_at',
        'reviewer_note',
    ];

    protected $casts = [
        'date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}

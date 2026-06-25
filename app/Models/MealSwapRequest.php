<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MealSwapRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'solicitante_id',
        'receptor_id',
        'fecha',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'fecha' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function solicitante()
    {
        return $this->belongsTo(Empleado::class, 'solicitante_id');
    }

    public function receptor()
    {
        return $this->belongsTo(Empleado::class, 'receptor_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AttendanceAbsenceRequest extends Model
{
    use HasUuids;

    protected $table = 'attendance_absence_requests';

    protected $fillable = [
        'empresa_id', 'empleado_id', 'date', 'motivo',
        'status', 'reviewed_by', 'reviewed_at', 'reviewer_note',
    ];

    protected $casts = [
        'date'        => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

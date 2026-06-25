<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AttendanceEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id', 'attendance_day_id', 'type', 'occurred_at', 'meta',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'meta' => 'array',
    ];

    public function attendanceDay()
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }
}

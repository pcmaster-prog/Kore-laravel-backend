<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AttendanceDay extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id','empleado_id','date','status',
        'first_check_in_at','last_check_out_at','totals',
        'lunch_start_at','lunch_end_at',
    ];

    protected $casts = [
        'date'              => 'date',
        'first_check_in_at' => 'datetime',
        'last_check_out_at' => 'datetime',
        'lunch_start_at'    => 'datetime',
        'lunch_end_at'      => 'datetime',
        'totals'            => 'array',
    ];

    public function events()
    {
        return $this->hasMany(AttendanceEvent::class, 'attendance_day_id');
    }
}
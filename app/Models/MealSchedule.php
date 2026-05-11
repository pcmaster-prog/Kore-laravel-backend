<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealSchedule extends Model
{
    protected $fillable = [
        'employee_id',
        'empresa_id',
        'meal_start_time',
        'duration_minutes',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
    ];

    /**
     * The employee this schedule belongs to.
     */
    public function employee()
    {
        return $this->belongsTo(Empleado::class, 'employee_id');
    }

    /**
     * The empresa this schedule belongs to.
     */
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}

<?php

// app/Models/PayrollEntry.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PayrollEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id', 'payroll_period_id', 'empleado_id',
        'payment_type', 'rate', 'units', 'rest_days_paid',
        'holidays_paid',
        'tardiness_count', 'absences_count',
        'subtotal', 'adjustment_amount', 'adjustment_note',
        'bonus_amount', 'bonus_note',
        'total', 'status', 'locked_at', 'locked_by',
    ];

    protected $casts = [
        'rate' => 'float',
        'units' => 'float',
        'rest_days_paid' => 'int',
        'holidays_paid' => 'int',
        'tardiness_count' => 'int',
        'absences_count' => 'int',
        'subtotal' => 'float',
        'adjustment_amount' => 'float',
        'bonus_amount' => 'float',
        'total' => 'float',
        'locked_at' => 'datetime',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function period()
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }
}

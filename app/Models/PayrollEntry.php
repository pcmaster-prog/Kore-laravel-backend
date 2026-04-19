<?php
// app/Models/PayrollEntry.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PayrollEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id', 'payroll_period_id', 'empleado_id',
        'payment_type', 'rate', 'units', 'rest_days_paid',
        'tardiness_count', 'absences_count',
        'subtotal', 'adjustment_amount', 'adjustment_note',
        'bonus_amount', 'bonus_note',
        'total',
    ];

    protected $casts = [
        'rate'              => 'float',
        'units'             => 'float',
        'rest_days_paid'    => 'int',
        'tardiness_count'   => 'int',
        'absences_count'    => 'int',
        'subtotal'          => 'float',
        'adjustment_amount' => 'float',
        'bonus_amount'      => 'float',
        'total'             => 'float',
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

<?php
// app/Models/PayrollPeriod.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PayrollPeriod extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id', 'week_start', 'week_end', 'status',
        'total_amount', 'total_adjustments', 'total_bonuses',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'week_start'    => 'date',
        'week_end'      => 'date',
        'total_amount'  => 'float',
        'total_adjustments' => 'float',
        'total_bonuses' => 'float',
        'approved_at'   => 'datetime',
    ];

    public function entries()
    {
        return $this->hasMany(PayrollEntry::class, 'payroll_period_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
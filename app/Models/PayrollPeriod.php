<?php
// app/Models/PayrollPeriod.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollPeriod extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'empresa_id', 'week_start', 'week_end', 'status', 'notes',
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
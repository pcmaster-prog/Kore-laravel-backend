<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class PayrollReceipt extends Model
{
    protected $fillable = [
        'payroll_period_id',
        'empleado_id',
        'user_id',
        'folio',
        'status',
        'period_start',
        'period_end',
        'payment_date',
        'employee_name',
        'position_title',
        'nss',
        'rfc',
        'curp',
        'daily_salary',
        'sbc',
        'days_worked',
        'perceptions',
        'total_perceptions',
        'deductions',
        'total_deductions',
        'net_pay',
        'net_pay_words',
        'payment_method',
        'bank_account',
        'clabe',
        'generated_at',
        'approved_at',
    ];

    protected $casts = [
        'perceptions' => 'array',
        'deductions' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'payment_date' => 'date',
        'daily_salary' => 'float',
        'sbc' => 'float',
        'total_perceptions' => 'float',
        'total_deductions' => 'float',
        'net_pay' => 'float',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function signature(): MorphOne
    {
        return $this->morphOne(ReceiptSignature::class, 'receivable');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($receipt) {
            if (empty($receipt->folio)) {
                $year = now()->format('Y');
                $week = now()->format('W');
                $last = static::whereYear('generated_at', $year)->count();
                $receipt->folio = sprintf("NOM-%s-%03d-%03d", $year, $week, $last + 1);
            }
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class GratificationReceipt extends Model
{
    protected $fillable = [
        'gratification_type_id',
        'empleado_id',
        'user_id',
        'folio',
        'status',
        'fiscal_year',
        'issue_date',
        'payment_date',
        'employee_name',
        'position_title',
        'nss',
        'rfc',
        'curp',
        'payment_breakdown',
        'total_gratification',
        'retentions',
        'total_retentions',
        'net_amount',
        'net_amount_words',
        'concept_description',
        'generated_at',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'payment_breakdown' => 'array',
        'retentions' => 'array',
        'issue_date' => 'date',
        'payment_date' => 'date',
        'total_gratification' => 'float',
        'total_retentions' => 'float',
        'net_amount' => 'float',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function gratificationType(): BelongsTo
    {
        return $this->belongsTo(GratificationType::class);
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
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
                $prefix = strtoupper(substr(optional($receipt->gratificationType)->code ?? 'GRAT', 0, 3));
                $year = now()->format('Y');
                $last = static::whereYear('issue_date', $year)->count();
                $receipt->folio = sprintf('G-%s-%s-%03d', $prefix, $year, $last + 1);
            }
        });
    }
}

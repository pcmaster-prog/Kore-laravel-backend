<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReceiptSignature extends Model
{
    protected $fillable = [
        'receivable_type',
        'receivable_id',
        'empleado_id',
        'user_id',
        'signature_image',
        'signature_image_path',
        'password_verified',
        'ip_address',
        'user_agent',
        'document_hash',
        'signed_at',
    ];

    protected $casts = [
        'password_verified' => 'boolean',
        'signed_at' => 'datetime',
    ];

    public function receivable(): MorphTo
    {
        return $this->morphTo();
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GratificationType extends Model
{
    protected $fillable = [
        'empresa_id',
        'code',
        'name',
        'description',
        'frequency',
        'is_active',
        'calculation_rules',
    ];

    protected $casts = [
        'calculation_rules' => 'array',
        'is_active' => 'boolean',
    ];

    public function receipts(): HasMany
    {
        return $this->hasMany(GratificationReceipt::class);
    }
}

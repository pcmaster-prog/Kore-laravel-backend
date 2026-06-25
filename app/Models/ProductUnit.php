<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id', 'name', 'abbreviation',
        'conversion_to_default', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'conversion_to_default' => 'decimal:4',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}

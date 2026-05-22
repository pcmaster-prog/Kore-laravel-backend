<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Area extends Model
{
    use HasUuids;

    protected $fillable = [
        'empresa_id',
        'name',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function sections()
    {
        return $this->hasMany(Section::class, 'area_id')->orderBy('sort_order');
    }

    public function activeSections()
    {
        return $this->hasMany(Section::class, 'area_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }
}

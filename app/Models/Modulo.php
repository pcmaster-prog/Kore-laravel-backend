<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Modulo extends Model
{
    use HasUuids;

    protected $fillable = ['key','name','is_premium'];

    protected $casts = [
        'is_premium' => 'boolean',
    ];
}

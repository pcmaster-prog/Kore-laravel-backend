<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class JobOpening extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'empresa_id',
        'title',
        'description',
        'requirements',
        'salary_range',
        'schedule',
        'status',
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
        ];
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}

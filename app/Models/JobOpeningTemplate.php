<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobOpeningTemplate extends Model
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
        'induction_video_url',
        'screening_questions',
        'screening_pass_score',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'screening_questions' => 'array',
            'is_active' => 'boolean',
            'screening_pass_score' => 'integer',
        ];
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}

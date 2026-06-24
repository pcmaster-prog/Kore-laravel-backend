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
        'about_us',
        'objective',
        'responsibilities',
        'education_requirements',
        'experience_requirements',
        'knowledge_requirements',
        'competencies',
        'performance_indicators',
        'offer_details',
        'closing_statement',
        'salary_range',
        'schedule',
        'location',
        'job_type',
        'department',
        'vacancies_count',
        'benefits',
        'tags',
        'is_featured',
        'published_at',
        'slug',
        'status',
        'image_url',
        'induction_video_url',
        'screening_questions',
        'screening_pass_score',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'responsibilities' => 'array',
            'education_requirements' => 'array',
            'experience_requirements' => 'array',
            'knowledge_requirements' => 'array',
            'competencies' => 'array',
            'performance_indicators' => 'array',
            'offer_details' => 'array',
            'screening_questions' => 'array',
            'screening_pass_score' => 'integer',
            'vacancies_count' => 'integer',
            'benefits' => 'array',
            'tags' => 'array',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function views()
    {
        return $this->hasMany(JobOpeningView::class);
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

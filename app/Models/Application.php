<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Application extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'empresa_id',
        'job_opening_id',
        'user_id',
        'status',
        'contact_info',
        'education',
        'experience',
        'has_induction_video_watched',
        'induction_video_watched_at',
        'screening_test_results',
        'interview_scheduled_at',
        'interview_notes',
        'interview_result',
        'manual_review_required',
        'manual_review_reason',
    ];

    protected function casts(): array
    {
        return [
            'contact_info' => 'array',
            'education' => 'array',
            'experience' => 'array',
            'screening_test_results' => 'array',
            'has_induction_video_watched' => 'boolean',
            'induction_video_watched_at' => 'datetime',
            'interview_scheduled_at' => 'datetime',
        ];
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function jobOpening()
    {
        return $this->belongsTo(JobOpening::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function documents()
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function statusLogs()
    {
        return $this->hasMany(ApplicationStatusLog::class);
    }
}

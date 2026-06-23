<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationOffer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'application_id',
        'position_id',
        'salary',
        'trial_months',
        'status',
        'sent_at',
        'accepted_at',
        'rejected_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'trial_months' => 'integer',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

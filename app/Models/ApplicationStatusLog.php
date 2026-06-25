<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationStatusLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'application_id',
        'from_status',
        'to_status',
        'changed_by',
        'notes',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

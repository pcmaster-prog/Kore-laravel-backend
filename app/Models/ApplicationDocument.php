<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'application_id',
        'document_type',
        'file_path',
        'disk',
        'original_name',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}

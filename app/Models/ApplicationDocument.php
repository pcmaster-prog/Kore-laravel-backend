<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ApplicationDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'application_id',
        'document_type',
        'file_path',
        'original_name',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModulePosition extends Model
{
    protected $table = 'module_position';

    protected $fillable = ['position_id', 'module_slug'];

    public function position()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }
}

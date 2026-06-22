<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserActivationToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Crea un token de activación para el usuario dado.
     * Invalida tokens previos del mismo usuario.
     */
    public static function createForUser(User $user, int $ttlHours = 24): self
    {
        self::where('user_id', $user->id)->delete();

        return self::create([
            'user_id' => $user->id,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addHours($ttlHours),
        ]);
    }
}

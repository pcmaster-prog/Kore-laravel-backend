<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasUuids, HasApiTokens, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'empresa_id',
        'name',
        'email',
        'password',
        'role',
        'section',
        'is_active',
        'provider',
        'provider_id',
        'avatar',
        'notifications_enabled',
        'language',
        'theme',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notifications_enabled' => 'boolean',
        ];
    }

    /**
     * Get the employee associated with the user.
     */
    public function empleado()
    {
        return $this->hasOne(Empleado::class);
    }

    /**
     * Get the empresa associated with the user.
     */
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    /**
     * Get the meal schedule for the user.
     */
    public function mealSchedule()
    {
        return $this->hasOne(MealSchedule::class, 'employee_id');
    }

    public function supervisedSections()
    {
        return $this->belongsToMany(Section::class, 'supervisor_sections', 'supervisor_user_id', 'section_id')
            ->withTimestamps();
    }

    public function applications()
    {
        return $this->hasMany(Application::class, 'user_id');
    }

    /**
     * Verifica si el usuario tiene alguno de los roles indicados.
     */
    public function hasRole(array|string $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];
        return in_array($this->role, $roles, true);
    }
}
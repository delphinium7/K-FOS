<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
    'name',
    'email',
    'password',
    'employee_id',
    'login_id',
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
     * @var array<string, string>
     */
   protected $casts = [
    'email_verified_at' => 'datetime',
    'password' => 'hashed',
    'is_active' => 'boolean',
    'is_system_admin' => 'boolean',
    'is_development_only' => 'boolean',
    'session_version' => 'integer',
];

    /** Current database-backed K-FOS permission; never use the legacy dev-admin flag. */
    public function hasKfosPermission(string $code): bool
    {
        return app(\App\Services\Security\KfosAuthorization::class)
            ->allows($this, $code);
    }

    public function isKfosSystemAdministrator(): bool
    {
        return app(\App\Services\Security\KfosAuthorization::class)
            ->isSystemAdministrator($this);
    }

}

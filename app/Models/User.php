<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $fillable = ['name', 'email', 'password', 'account_type', 'bio', 'favorite_game', 'game_interests', 'game_profiles', 'avatar_path'];
    protected $hidden = ['password', 'remember_token', 'avatar_path'];
    protected $appends = ['avatar_url'];
    protected function casts(): array { return ['email_verified_at' => 'immutable_datetime', 'password' => 'hashed', 'game_profiles' => 'array', 'game_interests' => 'array', 'is_platform_admin' => 'boolean', 'must_change_password' => 'boolean', 'temporary_password_expires_at' => 'immutable_datetime']; }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? "/api/v1/users/{$this->getKey()}/avatar?v=".($this->updated_at?->getTimestamp() ?? 0) : null;
    }
}

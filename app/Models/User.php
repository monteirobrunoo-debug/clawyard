<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    public function isAdmin(): bool     { return $this->role === 'admin'; }
    public function isManager(): bool   { return in_array($this->role, ['admin', 'manager']); }
    public function isGuest(): bool     { return $this->role === 'guest'; }

    public function conversationCount(): int
    {
        return \App\Models\Conversation::where('session_id', 'like', 'u' . $this->id . '_%')->count();
    }

    public function getRoleBadgeAttribute(): string
    {
        return match($this->role) {
            'admin'   => '🔴 Admin',
            'manager' => '🟡 Manager',
            'user'    => '🟢 User',
            'guest'   => '⚪ Guest',
            default   => $this->role,
        };
    }
}

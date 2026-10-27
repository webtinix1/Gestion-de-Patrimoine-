<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $username
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $job_title
 * @property bool $must_change_password
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 */
#[Fillable([
    'first_name',
    'last_name',
    'username',
    'email',
    'phone',
    'job_title',
    'password',
    'must_change_password',
    'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    // RG-84 : un compte désactivé ne peut plus se connecter.
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function getFilamentName(): string
    {
        return $this->fullName();
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}

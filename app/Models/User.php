<?php

namespace App\Models;

use App\Support\DefaultRoles;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

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
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /** @var array<string, list<int>|null> */
    private array $perimeterCache = [];

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

    // Rôle Administrateur sur un compte actif : seul habilité à gérer les comptes administrateurs, les rôles et les paramètres.
    public function isAdministrator(): bool
    {
        return $this->is_active && $this->hasRole(DefaultRoles::ADMIN);
    }

    // RG-85 : blocage temporaire après 5 échecs.
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * RG-91 : alertes que cet utilisateur a marquées comme lues.
     *
     * @return BelongsToMany<Alert, $this>
     */
    public function alertReads(): BelongsToMany
    {
        return $this->belongsToMany(Alert::class, 'alert_reads')->withPivot('read_at');
    }

    /**
     * @return BelongsToMany<Site, $this>
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    /**
     * RG-81 : sites accessibles, null si tous. Lu directement dans le pivot
     * pour ne pas déclencher le scope de périmètre du modèle Site.
     *
     * @return list<int>|null
     */
    public function perimeterSiteIds(): ?array
    {
        return $this->perimeterIds('site');
    }

    /**
     * @return list<int>|null
     */
    public function perimeterDepartmentIds(): ?array
    {
        return $this->perimeterIds('department');
    }

    public function forgetPerimeter(): void
    {
        $this->perimeterCache = [];
    }

    /**
     * @return list<int>|null
     */
    private function perimeterIds(string $type): ?array
    {
        if (! array_key_exists($type, $this->perimeterCache)) {
            $ids = DB::table("{$type}_user")
                ->where('user_id', $this->getKey())
                ->pluck("{$type}_id")
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            $this->perimeterCache[$type] = $ids === [] ? null : $ids;
        }

        return $this->perimeterCache[$type];
    }

    // RG-70, RG-71 : gestion des utilisateurs tracée, sans le mot de passe.
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('utilisateurs')
            ->logOnly(['first_name', 'last_name', 'username', 'email', 'phone', 'job_title', 'is_active', 'must_change_password'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event): string => match ($event) {
                'created' => 'Création d\'un utilisateur',
                'updated' => 'Modification d\'un utilisateur',
                default => $event,
            });
    }
}

<?php

namespace App\Filament\Pages\Auth;

use App\Models\Activity;
use App\Models\Department;
use App\Models\Site;
use App\Models\User;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * E04 : informations personnelles, changement de mot de passe, périmètre en lecture,
 * dernières opérations.
 */
class EditProfile extends BaseEditProfile
{
    public static function isSimple(): bool
    {
        return false;
    }

    public static function getLabel(): string
    {
        return 'Mon profil';
    }

    public function form(Schema $schema): Schema
    {
        $user = $this->currentUser();

        return $schema
            ->inlineLabel(false)
            ->components([
                Callout::make('Mot de passe provisoire')
                    ->description('Choisissez votre propre mot de passe avant de continuer : 8 caractères minimum, dont une lettre et un chiffre, différent de l\'actuel et sans votre identifiant.')
                    ->warning()
                    ->visible($user->must_change_password),
                Section::make('Informations personnelles')
                    ->columns(2)
                    ->schema([
                        TextInput::make('first_name')->label('Prénom')->required()->maxLength(255),
                        TextInput::make('last_name')->label('Nom')->required()->maxLength(255),
                        TextInput::make('phone')->label('Téléphone')->tel()->maxLength(30),
                        TextInput::make('email')->label('E-mail')->email()->maxLength(255)->unique(ignoreRecord: true),
                        TextInput::make('job_title')->label('Fonction')->maxLength(255)->columnSpanFull(),
                    ]),
                Section::make('Changer le mot de passe')
                    ->description('8 caractères minimum, dont une lettre et un chiffre, différent de l\'actuel et sans votre identifiant.')
                    ->schema([
                        $this->getCurrentPasswordFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
                Section::make('Périmètre d\'accès')
                    ->columns(2)
                    ->schema([
                        Text::make(fn (): HtmlString => $this->summary('Rôle', $user->getRoleNames()->join(', ') ?: 'Aucun')),
                        Text::make(fn (): HtmlString => $this->summary('Dernière connexion', $user->last_login_at
                            ? $user->last_login_at->format('d/m/Y à H:i').($user->last_login_ip ? ' · poste '.$user->last_login_ip : '')
                            : '—')),
                        Text::make(fn (): HtmlString => $this->summary('Sites', $this->names(Site::class, $user->perimeterSiteIds(), 'Tous les sites'))),
                        Text::make(fn (): HtmlString => $this->summary('Départements', $this->names(Department::class, $user->perimeterDepartmentIds(), 'Tous les départements'))),
                    ]),
                Section::make('Mes dernières opérations')
                    ->schema([
                        Text::make(fn (): HtmlString => $this->recentActivities($user)),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        if ($this->currentUser()->must_change_password && blank($data['password'] ?? null)) {
            throw ValidationException::withMessages([
                'data.password' => 'Choisissez un nouveau mot de passe pour remplacer le mot de passe provisoire.',
            ]);
        }

        if (filled($data['password'] ?? null)) {
            $data['must_change_password'] = false;
        }

        return $data;
    }

    /**
     * RG-85 : le nouveau mot de passe remplace vraiment l'ancien (ou le provisoire) et ne reprend pas
     * l'identifiant. Règle de validation du champ : la valeur est déjà hachée dans mutateFormDataBeforeSave.
     */
    protected function getPasswordFormComponent(): Component
    {
        $component = parent::getPasswordFormComponent();

        if ($component instanceof TextInput) {
            $component->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                $user = $this->currentUser();

                if (! is_string($value) || $value === '') {
                    return;
                }

                if (Hash::check($value, $user->password)) {
                    $fail('Le nouveau mot de passe doit être différent de l\'actuel.');
                }

                if (str_contains(mb_strtolower($value), mb_strtolower($user->username))) {
                    $fail('Le mot de passe ne doit pas contenir votre identifiant.');
                }
            });
        }

        return $component;
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function summary(string $label, string $value): HtmlString
    {
        return new HtmlString('<span class="pat-label">'.e($label).'</span><br>'.e($value));
    }

    /**
     * @param  class-string<Site|Department>  $model
     * @param  list<int>|null  $ids
     */
    private function names(string $model, ?array $ids, string $all): string
    {
        if ($ids === null) {
            return $all;
        }

        return $model::query()->withoutGlobalScopes()->whereKey($ids)->orderBy('name')->pluck('name')->join(', ');
    }

    private function recentActivities(User $user): HtmlString
    {
        $activities = Activity::query()
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey())
            ->latest('id')
            ->limit(8)
            ->get();

        if ($activities->isEmpty()) {
            return new HtmlString('<span class="text-gray-500">Aucune opération enregistrée.</span>');
        }

        $rows = $activities->map(fn (Activity $activity): string => '<li><span class="pat-mono">'
            .e($activity->created_at?->format('d/m/Y H:i')).'</span> · '.e($activity->description).'</li>')->join('');

        return new HtmlString('<ul class="space-y-1">'.$rows.'</ul>');
    }
}

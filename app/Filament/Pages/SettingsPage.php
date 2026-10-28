<?php

namespace App\Filament\Pages;

use App\Actions\Settings\UpdateSettings;
use App\Filament\Pages\Depreciation\DepreciationRulesPage;
use App\Http\Middleware\ApplySessionLifetime;
use App\Listeners\RecordFailedLogin;
use App\Models\AssetCategory;
use App\Models\Setting;
use App\Providers\AppServiceProvider;
use App\Services\AssetNumberGenerator;
use App\Services\Settings;
use App\Services\System\ServerStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * E41 · Paramètres : identité, numérotation (RG-04), alertes (RG-90), sécurité (RG-85, RG-66) et état du
 * serveur (lecture seule). Réservé à l'administrateur (SettingPolicy). Chaque enregistrement passe par
 * UpdateSettings, qui revalide tout. Le seuil d'immobilisation et le dégressif restent sur E32.
 *
 * @property-read Schema $form
 */
class SettingsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Paramètres';

    protected static ?string $title = 'Paramètres de l\'application';

    protected static ?string $slug = 'parametres';

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Setting::class);
    }

    public function mount(): void
    {
        $update = app(UpdateSettings::class);

        $this->form->fill([
            Settings::CHURCH_NAME => $update->current(Settings::CHURCH_NAME),
            Settings::CHURCH_ACRONYM => $update->current(Settings::CHURCH_ACRONYM),
            Settings::NUMBER_FORMAT => $update->current(Settings::NUMBER_FORMAT),
            Settings::NUMBER_PREFIX => $update->current(Settings::NUMBER_PREFIX),
            Settings::ALERT_LOAN_DUE_DAYS => $update->current(Settings::ALERT_LOAN_DUE_DAYS),
            Settings::ALERT_MAINTENANCE_DAYS => $update->current(Settings::ALERT_MAINTENANCE_DAYS),
            Settings::ALERT_INVENTORY_DAYS => $update->current(Settings::ALERT_INVENTORY_DAYS),
            Settings::ALERT_DEPRECIATION_MONTHS => $update->current(Settings::ALERT_DEPRECIATION_MONTHS),
            Settings::SESSION_LIFETIME_MINUTES => $update->current(Settings::SESSION_LIFETIME_MINUTES),
            'document_max_size_mb' => intdiv((int) $update->current(Settings::DOCUMENT_MAX_SIZE_KB), 1024),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $locked = fn (): bool => UpdateSettings::numberingLocked();
        $update = app(UpdateSettings::class);
        // Une valeur déjà enregistrée au-dessus de la limite de PHP reste affichable : seule une valeur plus haute est refusée.
        $maxMb = intdiv(max($update->documentMaxKb(), (int) $update->current(Settings::DOCUMENT_MAX_SIZE_KB)), 1024);

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('Paramètres')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Identité')->schema([
                            Section::make('Identité de l\'Église')
                                ->description('Reprise dans l\'en-tête des documents PDF (plans d\'amortissement, PV, fiches de prêt, rapports) et sur les étiquettes.')
                                ->schema([
                                    TextInput::make(Settings::CHURCH_NAME)->label('Nom de l\'Église')->required()->maxLength(UpdateSettings::NAME_MAX),
                                    TextInput::make(Settings::CHURCH_ACRONYM)->label('Sigle')->required()->maxLength(UpdateSettings::ACRONYM_MAX)
                                        ->helperText('Affiché sur les étiquettes et entre parenthèses dans les documents.'),
                                ]),
                        ]),
                        Tab::make('Numérotation')->schema([
                            Section::make('Numéro patrimonial')
                                ->description('RG-02 à RG-04 : un numéro attribué ne change jamais et n\'est jamais réutilisé, même après une sortie du patrimoine.')
                                ->schema([
                                    Text::make('Format et préfixe figés : des biens ont déjà reçu un numéro. Une modification créerait des numéros incohérents avec l\'existant (RG-04, Q10). Pour changer de format, contactez Webtinix.')
                                        ->color('warning')
                                        ->visible($locked),
                                    Radio::make(Settings::NUMBER_FORMAT)
                                        ->label('Format du numéro')
                                        ->options([
                                            Settings::FORMAT_GLOBAL => 'Numéro continu (PAT-000001)',
                                            Settings::FORMAT_YEARLY => 'Avec l\'année, compteur annuel (PAT-2026-000001)',
                                            Settings::FORMAT_CATEGORY => 'Par catégorie, préfixe de la catégorie (INF-000001)',
                                        ])
                                        ->live()
                                        ->disabled($locked)
                                        ->required(),
                                    TextInput::make(Settings::NUMBER_PREFIX)
                                        ->label('Préfixe')
                                        ->helperText('2 à 6 lettres ou chiffres. Sans effet avec le format « par catégorie ».')
                                        ->extraInputAttributes(['style' => 'font-family: "Martian Mono", monospace'])
                                        ->live(onBlur: true)
                                        ->disabled($locked)
                                        ->required(),
                                    Text::make(fn (Get $get): string => 'Exemple avec ce choix : '.$this->example((string) $get(Settings::NUMBER_FORMAT), (string) $get(Settings::NUMBER_PREFIX)))
                                        ->fontFamily('mono'),
                                    Text::make(fn (): string => 'Prochain numéro attribué : '.$this->nextNumbers())
                                        ->fontFamily('mono')
                                        ->weight('semibold'),
                                ]),
                        ]),
                        Tab::make('Alertes')->schema([
                            Section::make('Délais des alertes')
                                ->description('RG-90 : alertes internes, recalculées toutes les heures. Pas d\'e-mail ni de SMS en V1.')
                                ->schema([
                                    $this->number(Settings::ALERT_LOAN_DUE_DAYS, 'Prévenir avant l\'échéance d\'un prêt', 'jours', ...UpdateSettings::ALERT_BOUNDS[Settings::ALERT_LOAN_DUE_DAYS]),
                                    $this->number(Settings::ALERT_MAINTENANCE_DAYS, 'Signaler une intervention ouverte depuis', 'jours', ...UpdateSettings::ALERT_BOUNDS[Settings::ALERT_MAINTENANCE_DAYS]),
                                    $this->number(Settings::ALERT_INVENTORY_DAYS, 'Rappel d\'un inventaire planifié', 'jours avant', ...UpdateSettings::ALERT_BOUNDS[Settings::ALERT_INVENTORY_DAYS]),
                                    $this->number(Settings::ALERT_DEPRECIATION_MONTHS, 'Prévenir avant la fin d\'un amortissement', 'mois', ...UpdateSettings::ALERT_BOUNDS[Settings::ALERT_DEPRECIATION_MONTHS]),
                                    Text::make('Les nouveaux délais s\'appliquent au prochain calcul des alertes (toutes les heures).')->color('gray'),
                                ]),
                        ]),
                        Tab::make('Sécurité')->schema([
                            Section::make('Session et documents')
                                ->schema([
                                    $this->number(Settings::SESSION_LIFETIME_MINUTES, 'Déconnexion après inactivité', 'minutes', ApplySessionLifetime::MIN_MINUTES, ApplySessionLifetime::MAX_MINUTES)
                                        ->helperText('RG-85. S\'applique dès la requête suivante de chaque utilisateur connecté (de 5 à 480 minutes).'),
                                    TextInput::make('document_max_size_mb')
                                        ->label('Taille maximale d\'un document')
                                        ->helperText("RG-66. Au plus {$maxMb} Mo sur ce serveur (limite de PHP, voir l'onglet État du serveur).")
                                        ->numeric()->integer()->minValue(1)->maxValue($maxMb)
                                        ->suffix('Mo')
                                        ->required(),
                                ]),
                            Section::make('Règles fixes (lecture seule)')
                                ->description('RG-85. Ces règles sont appliquées par l\'application et ne se règlent pas ici.')
                                ->schema([
                                    Text::make('Mot de passe : '.$this->passwordRule()),
                                    Text::make('Blocage du compte pendant '.RecordFailedLogin::LOCK_MINUTES.' minutes après '.RecordFailedLogin::MAX_ATTEMPTS.' échecs de connexion consécutifs.'),
                                    Text::make('Mot de passe oublié : réinitialisation par l\'administrateur, avec mot de passe provisoire à changer à la première connexion.'),
                                ]),
                        ]),
                        Tab::make('État du serveur')->schema([
                            View::make('filament.pages.settings-server')
                                ->viewData(fn (): array => ['checks' => app(ServerStatus::class)->checks()]),
                        ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('depreciationRules')
                ->label('Règles d\'amortissement')
                ->icon(Heroicon::OutlinedScale)
                ->color('gray')
                ->url(fn (): string => DepreciationRulesPage::getUrl())
                ->tooltip('Seuil d\'immobilisation et méthode dégressive : réglés sur l\'écran Règles d\'amortissement.'),
            Action::make('save')
                ->label('Enregistrer')
                ->action(function (): void {
                    abort_unless(Gate::allows('update', Setting::class), 403);

                    $state = $this->form->getState();
                    $state[Settings::DOCUMENT_MAX_SIZE_KB] = is_numeric($state['document_max_size_mb'] ?? null) ? (int) $state['document_max_size_mb'] * 1024 : null;
                    unset($state['document_max_size_mb']);

                    try {
                        app(UpdateSettings::class)($state);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Paramètres non enregistrés')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();

                        $errors = [];

                        foreach ($exception->errors() as $key => $messages) {
                            $errors['data.'.($key === Settings::DOCUMENT_MAX_SIZE_KB ? 'document_max_size_mb' : $key)] = $messages;
                        }

                        throw ValidationException::withMessages($errors);
                    }

                    $this->mount();

                    Notification::make()->title('Paramètres enregistrés')->success()->send();
                }),
        ];
    }

    private function number(string $key, string $label, string $suffix, int $min, int $max): TextInput
    {
        return TextInput::make($key)->label($label)->numeric()->integer()->minValue($min)->maxValue($max)->suffix($suffix)->required();
    }

    private function passwordRule(): string
    {
        return AppServiceProvider::PASSWORD_MINIMUM.' caractères minimum, dont au moins un chiffre.';
    }

    private function example(string $format, string $prefix): string
    {
        $prefix = mb_strtoupper(trim($prefix)) ?: 'PAT';

        return match ($format) {
            Settings::FORMAT_YEARLY => "{$prefix}-".now()->year.'-000001',
            Settings::FORMAT_CATEGORY => 'INF-000001 (préfixe de la catégorie)',
            default => "{$prefix}-000001",
        };
    }

    private function nextNumbers(): string
    {
        $generator = app(AssetNumberGenerator::class);
        $categories = AssetCategory::query()->where('is_active', true)->orderBy('name')->limit(4)->get();

        if ($categories->isEmpty()) {
            $categories = collect([new AssetCategory(['prefix' => 'XXX'])]);
        }

        if (app(Settings::class)->string(Settings::NUMBER_FORMAT) !== Settings::FORMAT_CATEGORY) {
            return $generator->preview($categories->first() ?? new AssetCategory(['prefix' => 'XXX']));
        }

        return $categories->map(fn (AssetCategory $category): string => $generator->preview($category))->implode(', ');
    }
}

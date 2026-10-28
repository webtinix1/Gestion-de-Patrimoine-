<?php

namespace App\Filament\Pages;

use App\Actions\Alerts\MarkAlertsRead;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\Loan;
use App\Models\MaintenanceJob;
use App\Models\User;
use App\Services\Alerts\AlertCounter;
use App\Services\Alerts\AlertPresenter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * E03 · RG-90, RG-91 : alertes internes, calculées toutes les heures. Filtre par type, « tout marquer comme lu ».
 * Chaque utilisateur ne voit que les alertes de son périmètre et des modules qu'il peut consulter.
 */
class Alerts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Accueil';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Alertes';

    protected static ?string $title = 'Alertes';

    protected static ?string $slug = 'alertes';

    protected string $view = 'filament.pages.alerts';

    #[Url(as: 'type')]
    public string $group = 'all';

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::unreadCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function unreadCount(): int
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return 0;
        }

        // Mémorisé pour la requête (menu, cloche, titre) ; effacé quand on marque ou recalcule.
        return app(AlertCounter::class)->unread($user);
    }

    public function mount(): void
    {
        if ($this->group !== 'all' && ! array_key_exists($this->group, AlertType::groups())) {
            $this->group = 'all';
        }
    }

    public function selectGroup(string $group): void
    {
        $this->group = array_key_exists($group, AlertType::groups()) ? $group : 'all';
    }

    public function markRead(int $id): void
    {
        app(MarkAlertsRead::class)($this->user(), [$id]);
    }

    public function markAllRead(): void
    {
        app(MarkAlertsRead::class)($this->user());
    }

    public function getSubheading(): ?string
    {
        $count = self::unreadCount();

        return ($count === 0 ? 'Aucune alerte non lue' : $count.' '.($count > 1 ? 'alertes non lues' : 'alerte non lue')).'. Les alertes sont générées automatiquement par l\'application.';
    }

    /**
     * Alertes du périmètre de l'utilisateur, prêtes à afficher.
     *
     * @return list<array{id: int, type: AlertType, group: string, title: string, detail: string, when: string, href: string|null, action: string, unread: bool}>
     */
    public function alerts(): array
    {
        $user = $this->user();
        $presenter = app(AlertPresenter::class);
        $alerts = Alert::query()->active()->visibleTo($user)->with(['subject' => fn (MorphTo $morph) => $morph->morphWith([
            Loan::class => ['items.asset'],
            MaintenanceJob::class => ['asset'],
            Inventory::class => ['manager'],
            Asset::class => ['category'],
        ])])->orderByDesc('raised_at')->orderByDesc('id')->get();
        $readIds = $user->alertReads()->pluck('alert_reads.alert_id')->all();

        return $alerts
            ->map(fn (Alert $alert): ?array => $presenter->present($alert, $user, ! in_array($alert->id, $readIds, true)))
            ->filter()
            ->values()
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllRead')
                ->label('Tout marquer comme lu')
                ->color('gray')
                ->visible(fn (): bool => self::unreadCount() > 0)
                ->action(fn () => $this->markAllRead()),
        ];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

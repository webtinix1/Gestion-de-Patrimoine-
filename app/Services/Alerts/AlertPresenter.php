<?php

namespace App\Services\Alerts;

use App\Enums\AlertType;
use App\Enums\InventoryLineResult;
use App\Enums\LoanDirection;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceJob;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Texte d'une alerte (E03) construit à l'affichage à partir de l'objet concerné : il est lu avec le
 * périmètre de l'utilisateur connecté, une alerte n'expose donc jamais un bien hors périmètre (RG-81).
 */
class AlertPresenter
{
    /**
     * @return array{id: int, type: AlertType, group: string, title: string, detail: string, when: string, href: string|null, action: string, unread: bool}|null
     */
    public function present(Alert $alert, User $user, bool $unread): ?array
    {
        $subject = $alert->subject;

        if (! $subject instanceof Model) {
            return null;
        }

        [$title, $detail, $href] = match (true) {
            $subject instanceof Loan => $this->loan($alert, $subject, $user),
            $subject instanceof MaintenanceJob => $this->maintenance($subject, $user),
            $subject instanceof Inventory => $this->inventory($alert, $subject, $user),
            $subject instanceof Asset => $this->asset($alert, $subject, $user),
            default => [null, null, null],
        };

        if ($title === null) {
            return null;
        }

        return [
            'id' => $alert->id,
            'type' => $alert->type,
            'group' => $alert->type->group(),
            'title' => $title,
            'detail' => (string) $detail,
            'when' => self::since($alert->raised_at),
            'href' => $href,
            'action' => $alert->type->actionLabel(),
            'unread' => $unread,
        ];
    }

    public static function since(CarbonInterface $date): string
    {
        $days = (int) $date->copy()->startOfDay()->diffInDays(today());

        return match (true) {
            $days <= 0 => 'Aujourd\'hui',
            $days === 1 => 'Hier',
            default => 'Il y a '.$days.' jours',
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function loan(Alert $alert, Loan $loan, User $user): array
    {
        $items = $loan->items;
        $labels = $items->map(fn (LoanItem $item): string => $item->label())->values();
        $title = ($labels->first() ?? $loan->reference).($labels->count() > 1 ? ' et '.($labels->count() - 1).' autre(s)' : '');
        $due = $loan->due_on->format('d/m/Y');
        $who = $loan->direction === LoanDirection::In ? 'Emprunté à '.$loan->borrower_name : 'Prêté à '.$loan->borrower_name;

        $detail = $alert->type === AlertType::LoanOverdue
            ? $who.', retour prévu le '.$due.' ('.$loan->daysLate().' jour(s) de retard)'
            : 'Retour prévu le '.$due.', '.($loan->direction === LoanDirection::In ? 'propriétaire' : 'emprunteur').' : '.$loan->borrower_name;

        return [$title, $detail, $user->can('view', $loan) ? LoanResource::getUrl('view', ['record' => $loan]) : null];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function maintenance(MaintenanceJob $job, User $user): array
    {
        $asset = $job->asset;
        $days = $job->starts_on->diffInDays(today());
        $detail = $job->nature->getLabel().($job->provider_name ? ' chez '.$job->provider_name : '').' depuis le '.$job->starts_on->format('d/m/Y').' ('.$days.' jours)';

        return [$asset->label().' · '.$asset->number, $detail, $user->can('view', $job) ? MaintenanceResource::getUrl('view', ['record' => $job]) : null];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function inventory(Alert $alert, Inventory $inventory, User $user): array
    {
        $title = $inventory->reference.' · '.$inventory->scopeLabel();

        if ($alert->type === AlertType::InventoryUpcoming) {
            $detail = 'Planifié '.($inventory->ends_on === null || $inventory->ends_on->isSameDay($inventory->starts_on) ? 'le ' : 'du ').$inventory->periodLabel().', responsable : '.($inventory->manager->fullName());

            return [$title, $detail, InventoryResource::getUrl('index')];
        }

        $lines = $inventory->lines()->whereIn('result', InventoryLineResult::discrepancyValues())->whereNull('resolution')->get();
        $missing = $lines->where('result', InventoryLineResult::Missing)->count();
        $others = $lines->count() - $missing;
        $detail = $missing.' bien(s) manquant(s) et '.$others.' autre(s) écart(s) restent à régulariser';

        return [$title, $detail, $user->can('view', $inventory) ? InventoryResource::getUrl('report', ['record' => $inventory]) : null];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function asset(Alert $alert, Asset $asset, User $user): array
    {
        $title = $asset->label().' · '.$asset->number;

        if ($alert->type === AlertType::OutOfService) {
            $detail = 'Déclaré hors service, en attente de décision (réparation ou sortie du patrimoine)';
            $url = $user->can('view', $asset) ? AssetResource::getUrl('view', ['record' => $asset]) : null;

            return [$title, $detail, $url];
        }

        $ends = $alert->event_on;
        $detail = 'Amortissement '.($ends !== null && $ends->isFuture() ? 'se termine le ' : 'terminé le ').($ends?->format('d/m/Y') ?? '—').' (catégorie '.($asset->category->name ?? '—').')';

        return [$title, $detail, $user->can('view', $asset) ? AssetResource::getUrl('depreciation', ['record' => $asset]) : null];
    }
}

<?php

namespace App\Actions\Alerts;

use App\Models\Alert;
use App\Models\User;
use App\Services\Alerts\AlertCounter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * RG-91 : marque des alertes comme lues pour l'utilisateur seul. Seules les alertes qu'il a le droit de voir
 * (droits et périmètre) sont prises en compte : un identifiant venu du navigateur est revalidé ici.
 */
class MarkAlertsRead
{
    /**
     * @param  list<int>|null  $ids  null : toutes les alertes visibles non lues
     */
    public function __invoke(User $user, ?array $ids = null): int
    {
        // Le périmètre se lit avec l'utilisateur connecté : on ne marque que pour soi.
        if (auth()->id() !== $user->getKey()) {
            throw new AuthorizationException('Une alerte se marque comme lue pour soi-même.');
        }

        $alertIds = Alert::query()->active()->visibleTo($user)->unreadBy($user)
            ->when($ids !== null, fn ($query) => $query->whereKey($ids))
            ->pluck('alerts.id');

        $now = now();

        DB::table('alert_reads')->insertOrIgnore($alertIds->map(fn (int $id): array => [
            'alert_id' => $id,
            'user_id' => $user->getKey(),
            'read_at' => $now,
        ])->all());

        app(AlertCounter::class)->forget();

        return $alertIds->count();
    }
}

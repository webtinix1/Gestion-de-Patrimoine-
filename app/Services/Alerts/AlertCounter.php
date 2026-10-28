<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\User;

/**
 * Nombre d'alertes non lues, calculé une fois par requête (badge du menu, cloche, sous-titre).
 */
class AlertCounter
{
    /** @var array<int, int> */
    private array $counts = [];

    public function unread(User $user): int
    {
        return $this->counts[$user->getKey()] ??= Alert::query()->active()->visibleTo($user)->unreadBy($user)->count();
    }

    public function forget(): void
    {
        $this->counts = [];
    }
}

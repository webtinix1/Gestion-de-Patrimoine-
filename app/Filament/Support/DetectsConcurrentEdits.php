<?php

namespace App\Filament\Support;

use App\Exceptions\ConcurrentModificationException;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;

/**
 * RG-86 : retient la version de la fiche à son ouverture (non modifiable par le navigateur)
 * et prévient l'utilisateur si l'action métier refuse l'enregistrement. Aucun écrasement
 * silencieux. À utiliser sur une page EditRecord dont le modèle a une colonne lock_version.
 */
trait DetectsConcurrentEdits
{
    #[Locked]
    public ?int $openedLockVersion = null;

    protected function afterFill(): void
    {
        $this->openedLockVersion = (int) $this->getRecord()->getAttribute('lock_version');
    }

    protected function notifyConcurrentModification(ConcurrentModificationException $exception): void
    {
        Notification::make()
            ->title('Cette fiche a été modifiée entre-temps')
            ->body($exception->getMessage())
            ->danger()
            ->persistent()
            ->send();

        $this->halt();
    }
}

<?php

namespace App\Filament\Support;

use Livewire\Attributes\Locked;

/**
 * RG-86 : les versions des fiches vues à l'ouverture d'une fenêtre sont gardées côté serveur (propriété verrouillée),
 * jamais dans un champ du formulaire que le navigateur pourrait modifier ou retirer.
 */
trait HoldsOperationVersions
{
    /** @var array<int, int> */
    #[Locked]
    public array $heldVersions = [];

    /**
     * @param  array<int|string, mixed>  $versions  version par identifiant de bien
     * @param  bool  $replace  true : nouvelle ouverture (remplace tout) ; false : sélection (garde la version déjà vue)
     */
    public function holdVersions(array $versions, bool $replace = false): void
    {
        $held = $replace ? [] : $this->heldVersions;
        $next = [];

        foreach ($versions as $id => $version) {
            $next[(int) $id] = $held[(int) $id] ?? (int) $version;
        }

        $this->heldVersions = $next;
    }

    public function heldVersion(int $assetId): ?int
    {
        return $this->heldVersions[$assetId] ?? null;
    }
}

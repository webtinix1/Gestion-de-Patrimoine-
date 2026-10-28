<?php

namespace App\Actions\Inventories;

use App\Enums\InventoryResolution;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\User;
use App\Services\Inventories\InventoryJournal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RG-48 : relie une régularisation à la ligne d'un inventaire clôturé (resolution, resolution_ref),
 * et la trace (RG-70). Une ligne n'est régularisée qu'une fois.
 *
 * Contrat public : le module Sorties (lot 10) l'appelle quand la sortie pour perte d'un bien manquant
 * est enregistrée, dans sa propre transaction (après avoir verrouillé le bien) :
 *
 *     app(ResolveInventoryLine::class)($line, InventoryResolution::Disposal, $disposal);
 *
 * L'objet de référence doit porter le bien de la ligne (asset_id), sauf pour une fiche créée (le bien
 * lui-même) et « retrouvé » (sans objet). Les droits sur l'opération sont vérifiés par l'appelant.
 */
class ResolveInventoryLine
{
    public function __invoke(InventoryLine $line, InventoryResolution|string $type, ?Model $reference, ?string $notes = null, ?User $user = null): InventoryLine
    {
        $type = $type instanceof InventoryResolution ? $type : InventoryResolution::tryFrom($type);

        if ($type === null) {
            throw ValidationException::withMessages(['resolution' => 'Type de régularisation inconnu.']);
        }

        $user ??= auth()->user() instanceof User ? auth()->user() : null;

        // Transaction imbriquée dans celle de l'appelant : les tentatives sont celles de la plus externe.
        return DB::transaction(function () use ($line, $type, $reference, $notes, $user): InventoryLine {
            $current = InventoryLine::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->getKey());
            $inventory = Inventory::query()->withoutGlobalScopes()->findOrFail($current->inventory_id);

            if (! $inventory->isClosed()) {
                throw ValidationException::withMessages(['resolution' => 'Les régularisations se font après la clôture de l\'inventaire (RG-48).']);
            }

            if ($current->resolution !== null) {
                throw ValidationException::withMessages(['resolution' => 'Cette ligne est déjà régularisée ('.$current->resolution->getLabel().').']);
            }

            if (! in_array($current->result, $type->appliesTo(), true)) {
                throw ValidationException::withMessages(['resolution' => '« '.$type->getLabel().' » ne s\'applique pas à un résultat « '.$current->result->getLabel().' ».']);
            }

            $this->assertReference($current, $type, $reference);

            $notes = trim((string) $notes);
            $current->forceFill([
                'resolution' => $type,
                'resolution_ref_type' => $reference?->getMorphClass(),
                'resolution_ref_id' => $reference?->getKey(),
                'resolution_notes' => $notes === '' ? null : $notes,
                'resolved_by' => $user?->id,
                'resolved_at' => now(),
            ])->save();

            $subject = $current->asset_id !== null ? Asset::query()->withoutGlobalScopes()->find($current->asset_id) : null;
            $subject ??= $reference instanceof Asset ? $reference : $inventory;

            $logger = activity(InventoryJournal::LOG_NAME)
                ->performedOn($subject)
                ->event('inventory_line_resolved')
                ->withProperties([
                    'old' => ['régularisation' => null],
                    'attributes' => array_filter([
                        'régularisation' => $type->getLabel(),
                        'référence' => $reference === null ? null : class_basename($reference).' n° '.$reference->getKey(),
                        'observations' => $current->resolution_notes,
                    ]),
                    'inventory' => $inventory->reference,
                    'inventory_id' => $inventory->id,
                    'line_id' => $current->id,
                ]);

            if ($user !== null) {
                $logger->causedBy($user);
            }

            $logger->log('Régularisation d\'inventaire '.$inventory->reference);

            $line->setRawAttributes($current->getAttributes(), sync: true);

            return $line;
        }, attempts: 1);
    }

    private function assertReference(InventoryLine $line, InventoryResolution $type, ?Model $reference): void
    {
        if ($type === InventoryResolution::Found) {
            return;
        }

        if ($reference === null || ! $reference->exists) {
            throw ValidationException::withMessages(['resolution' => 'La régularisation doit être liée à l\'opération enregistrée.']);
        }

        if ($type === InventoryResolution::CreatedAsset) {
            if (! $reference instanceof Asset) {
                throw ValidationException::withMessages(['resolution' => 'La fiche créée doit être un bien.']);
            }

            return;
        }

        $assetId = $reference instanceof Asset ? $reference->getKey() : $reference->getAttribute('asset_id');

        if ((int) $assetId !== $line->asset_id) {
            throw ValidationException::withMessages(['resolution' => 'L\'opération ne porte pas sur le bien de cette ligne.']);
        }
    }
}

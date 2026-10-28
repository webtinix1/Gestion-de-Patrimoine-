<?php

namespace App\Actions\Inventories;

use App\Enums\InventoryResolution;
use App\Enums\MovementType;
use App\Models\Asset;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Movement;
use App\Models\User;
use App\Services\Inventories\InventoryJournal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
 * Objet attendu : transfert → mouvement (hors affectation initiale) du bien, postérieur à la clôture ;
 * état → la fiche du bien ; fiche créée → bien créé après la clôture, lié à aucune autre ligne ;
 * sortie → App\Models\Disposal du bien ; retrouvé → aucun. Les droits sont vérifiés par l'appelant.
 */
class ResolveInventoryLine
{
    /** Classe des sorties du patrimoine (lot 10), vérifiée par son nom sans en dépendre ici. */
    public const string DISPOSAL_CLASS = 'App\Models\Disposal';

    public function __invoke(InventoryLine $line, InventoryResolution|string $type, ?Model $reference, ?string $notes = null, ?User $user = null): InventoryLine
    {
        $type = $type instanceof InventoryResolution ? $type : InventoryResolution::tryFrom($type);

        if ($type === null) {
            throw ValidationException::withMessages(['resolution' => 'Type de régularisation inconnu.']);
        }

        $user ??= auth()->user() instanceof User ? auth()->user() : null;

        // Transaction imbriquée dans celle de l'appelant : les tentatives sont celles de la plus externe.
        return DB::transaction(function () use ($line, $type, $reference, $notes, $user): InventoryLine {
            if ($reference instanceof Asset) {
                Asset::query()->withoutGlobalScopes()->whereKey($reference->getKey())->lockForUpdate()->first();
            }

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

            $this->assertReference($current, $inventory, $type, $reference);

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

    /**
     * RG-48 : à chaque régularisation sa classe d'opération et ses conditions.
     */
    private function assertReference(InventoryLine $line, Inventory $inventory, InventoryResolution $type, ?Model $reference): void
    {
        if ($type === InventoryResolution::Found) {
            return;
        }

        $fail = fn (string $message) => throw ValidationException::withMessages(['resolution' => $message]);

        if ($reference === null || ! $reference->exists) {
            $fail('La régularisation doit être liée à l\'opération enregistrée.');
        }

        $closedAt = $inventory->closed_at;
        $after = fn (Model $model): bool => $closedAt !== null && $model->getAttribute('created_at') !== null
            && ! Carbon::parse($model->getAttribute('created_at'))->lt($closedAt);

        switch ($type) {
            case InventoryResolution::Transfer:
                if (! $reference instanceof Movement || $reference->type === MovementType::Initial
                    || $reference->asset_id !== $line->asset_id || ! $after($reference)) {
                    $fail('Un transfert de régularisation est un mouvement du bien de la ligne, enregistré après la clôture.');
                }
                break;

            case InventoryResolution::ConditionUpdate:
                if (! $reference instanceof Asset || $reference->getKey() !== $line->asset_id) {
                    $fail('La mise à jour d\'état porte sur la fiche du bien de la ligne.');
                }
                break;

            case InventoryResolution::CreatedAsset:
                if (! $reference instanceof Asset || $line->asset_id !== null || ! $after($reference)) {
                    $fail('La fiche créée doit être un bien nouveau, enregistré après la clôture.');
                }

                $taken = InventoryLine::query()->withoutGlobalScopes()
                    ->whereKeyNot($line->getKey())
                    ->where('resolution', InventoryResolution::CreatedAsset)
                    ->where('resolution_ref_type', $reference->getMorphClass())
                    ->where('resolution_ref_id', $reference->getKey())
                    ->exists();

                if ($taken) {
                    $fail('Cette fiche est déjà liée à un autre bien trouvé.');
                }
                break;

            case InventoryResolution::Disposal:
                // Contrat avec le lot 10 : seule une sortie (App\Models\Disposal) du bien de la ligne.
                $disposedAssetId = (int) $reference->getAttribute('asset_id');

                if ($reference::class !== self::DISPOSAL_CLASS || $disposedAssetId !== $line->asset_id) {
                    $fail('Une sortie pour perte est une sortie du patrimoine du bien de la ligne.');
                }
                break;

            default:
                $fail('Type de régularisation inconnu.');
        }
    }
}

<?php

namespace App\Services\Inventories;

use App\Enums\InventoryLineResult;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;

/**
 * RG-70 : journal des inventaires (démarrage, saisie, clôture, régularisations), avec avant et après.
 * Une saisie est rattachée au bien (elle apparaît dans le journal de sa fiche et suit son périmètre) ;
 * un bien non enregistré, à l'inventaire. Les noms sont écrits sans passer par les scopes de périmètre.
 */
class InventoryJournal
{
    public const string LOG_NAME = 'inventaires';

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $extra
     */
    public function inventory(Inventory $inventory, string $event, string $description, array $old = [], array $new = [], array $extra = [], ?User $user = null): void
    {
        $logger = activity(self::LOG_NAME)
            ->performedOn($inventory)
            ->event($event)
            ->withProperties(['old' => $old, 'attributes' => $new, 'inventory' => $inventory->reference, ...$extra]);

        if ($user !== null) {
            $logger->causedBy($user);
        }

        $logger->log($description);
    }

    /**
     * Résultat d'une ligne, par bien et par utilisateur (RG-47, RG-70).
     *
     * @param  array<string, mixed>  $before  état de la ligne avant la saisie
     */
    public function line(InventoryLine $line, Inventory $inventory, array $before, User $user, string $description = 'Saisie d\'inventaire'): void
    {
        $subject = $line->asset_id !== null ? Asset::query()->withoutGlobalScopes()->find($line->asset_id) : $inventory;

        activity(self::LOG_NAME)
            ->performedOn($subject ?? $inventory)
            ->causedBy($user)
            ->event('inventory_line_recorded')
            ->withProperties([
                'old' => $before === [] ? [] : $this->readable($before),
                'attributes' => $this->readable($line->only(['result', 'found_room_id', 'found_department_id', 'found_condition_id', 'description', 'notes'])),
                'inventory' => $inventory->reference,
                'inventory_id' => $inventory->getKey(),
                'line_id' => $line->getKey(),
            ])
            ->log($description.' '.$inventory->reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(InventoryLine $line): array
    {
        return $line->only(['result', 'found_room_id', 'found_department_id', 'found_condition_id', 'description', 'notes']);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function readable(array $values): array
    {
        $readable = [];

        foreach ($values as $key => $value) {
            $readable[match ($key) {
                'result' => 'résultat',
                'found_room_id' => 'local constaté',
                'found_department_id' => 'département constaté',
                'found_condition_id' => 'état constaté',
                default => $key,
            }] = match ($key) {
                'result' => $value instanceof InventoryLineResult ? $value->getLabel() : $value,
                'found_room_id' => $value === null ? null : Room::query()->withoutGlobalScopes()->find($value)?->name,
                'found_department_id' => $value === null ? null : Department::query()->withoutGlobalScopes()->find($value)?->name,
                'found_condition_id' => $value === null ? null : AssetCondition::query()->find($value)?->label,
                'found_site_id' => $value === null ? null : Site::query()->withoutGlobalScopes()->find($value)?->name,
                default => $value,
            };
        }

        return array_filter($readable, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}

<?php

namespace App\Actions\Inventories;

use App\Models\Inventory;
use App\Models\User;
use App\Services\Inventories\InventoryInput;
use App\Services\Inventories\InventoryJournal;
use App\Services\Inventories\InventoryReferenceGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E19 : enregistre un inventaire planifié (RG-44), avec sa référence INV-2026-005 attribuée sous verrou.
 */
class CreateInventory
{
    public function __construct(
        private InventoryInput $input,
        private InventoryReferenceGenerator $references,
        private InventoryJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data, User $user): Inventory
    {
        if (! $user->can('create', Inventory::class)) {
            throw new AuthorizationException('Vous n\'avez pas le droit de créer un inventaire.');
        }

        $input = $this->input->normalize($data, $user);

        // Transaction la plus externe, rejouée en cas d'interblocage sur le compteur.
        return DB::transaction(function () use ($input, $user): Inventory {
            $header = $input['header'];
            $inventory = new Inventory($header);
            $inventory->forceFill([
                'reference' => $this->references->next(Carbon::parse($header['starts_on'])->year),
                'perimeter_site_id' => $header['perimeter_site_id'],
                'perimeter_department_id' => $header['perimeter_department_id'],
            ]);
            $inventory->save();
            $inventory->participants()->createMany($input['participants']);

            $this->journal->inventory($inventory, 'created', 'Création d\'un inventaire', [], [
                'périmètre' => $inventory->scopeLabel(),
                'période' => $inventory->periodLabel(),
                'participants' => count($input['participants']),
            ], user: $user);

            return $inventory;
        }, attempts: 5);
    }
}

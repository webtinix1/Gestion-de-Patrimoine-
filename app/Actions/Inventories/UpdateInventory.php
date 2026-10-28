<?php

namespace App\Actions\Inventories;

use App\Exceptions\ConcurrentModificationException;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Inventories\InventoryInput;
use App\Services\Inventories\InventoryJournal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modifie un inventaire planifié (périmètre, dates, participants). Une fois démarré, la liste est figée
 * et le périmètre ne change plus (RG-44). RG-86 : contrôle de la version d'ouverture sous verrou.
 */
class UpdateInventory
{
    public function __construct(
        private InventoryInput $input,
        private InventoryJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Inventory $inventory, array $data, User $user, ?int $expectedVersion = null): Inventory
    {
        $input = $this->input->normalize($data, $user);

        return DB::transaction(function () use ($inventory, $input, $user, $expectedVersion): Inventory {
            $current = Inventory::query()->lockForUpdate()->findOrFail($inventory->getKey());

            if (! $user->can('update', $current)) {
                throw new AuthorizationException('Seul un inventaire planifié de votre périmètre se modifie.');
            }

            if ($expectedVersion !== null && $current->lock_version !== $expectedVersion) {
                throw new ConcurrentModificationException(
                    $current->updated_by ? User::query()->find($current->updated_by) : null,
                    $current->updated_at,
                );
            }

            $header = $input['header'];

            // La référence porte l'année de la date de début.
            if (Carbon::parse($header['starts_on'])->year !== $current->starts_on->year) {
                throw ValidationException::withMessages([
                    'data.starts_on' => 'La référence '.$current->reference.' porte l\'année '.$current->starts_on->year.' : la date de début doit rester dans cette année.',
                ]);
            }

            $before = ['périmètre' => $current->scopeLabel(), 'période' => $current->periodLabel()];

            $current->fill($header);
            $current->forceFill([
                'perimeter_site_id' => $header['perimeter_site_id'],
                'perimeter_department_id' => $header['perimeter_department_id'],
            ]);
            $current->save();

            $current->participants()->whereNotIn('user_id', array_column($input['participants'], 'user_id'))->delete();

            foreach ($input['participants'] as $participant) {
                $current->participants()->updateOrCreate(['user_id' => $participant['user_id']], ['room_id' => $participant['room_id']]);
            }

            $this->journal->inventory($current, 'updated', 'Modification d\'un inventaire planifié', $before, [
                'périmètre' => $current->scopeLabel(),
                'période' => $current->periodLabel(),
                'participants' => count($input['participants']),
            ], user: $user);

            $inventory->setRawAttributes($current->getAttributes(), sync: true);

            return $inventory;
        }, attempts: 3);
    }
}

<?php

namespace App\Filament\Resources\Inventories\Pages;

use App\Actions\Inventories\CreateInventory as CreateInventoryAction;
use App\Actions\Inventories\StartInventory;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\Inventory;
use App\Models\User;
use App\Services\Inventories\InventoryReferenceGenerator;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * E19 : « Planifier » enregistre l'inventaire ; « Démarrer maintenant » l'enregistre et fige la liste.
 */
class CreateInventory extends CreateRecord
{
    protected static string $resource = InventoryResource::class;

    protected static bool $canCreateAnother = false;

    private bool $startNow = false;

    public function getTitle(): string|Htmlable
    {
        return 'Nouvel inventaire';
    }

    public function getSubheading(): string
    {
        return 'Référence attribuée à l\'enregistrement (prochaine : '.app(InventoryReferenceGenerator::class)->preview((int) now()->year).').';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Inventory
    {
        /** @var User $user */
        $user = auth()->user();

        return DB::transaction(function () use ($data, $user): Inventory {
            $inventory = app(CreateInventoryAction::class)($data, $user);

            if ($this->startNow) {
                app(StartInventory::class)($inventory, $user);
            }

            return $inventory;
        }, attempts: 5);
    }

    public function createAndStart(): void
    {
        $this->startNow = true;
        $this->create();
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Planifier')->color('gray'),
            Action::make('createAndStart')
                ->label('Démarrer maintenant')
                ->action(fn () => $this->createAndStart()),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var Inventory $inventory */
        $inventory = $this->getRecord();

        return $inventory->isInProgress()
            ? 'Inventaire '.$inventory->reference.' démarré : liste des biens figée'
            : 'Inventaire '.$inventory->reference.' planifié';
    }

    protected function getRedirectUrl(): string
    {
        /** @var Inventory $inventory */
        $inventory = $this->getRecord();

        return InventoryResource::urlFor($inventory);
    }
}

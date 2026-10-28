<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** RG-48 : régularisation faite après la clôture, depuis le rapport, liée à la ligne. */
enum InventoryResolution: string implements HasLabel
{
    case Transfer = 'transfer';
    case ConditionUpdate = 'condition_update';
    case Disposal = 'disposal';
    case CreatedAsset = 'created_asset';
    case Found = 'found';

    public function getLabel(): string
    {
        return match ($this) {
            self::Transfer => 'Régularisé par transfert',
            self::ConditionUpdate => 'État mis à jour',
            self::Disposal => 'Sortie pour perte',
            self::CreatedAsset => 'Fiche créée',
            self::Found => 'Retrouvé',
        };
    }

    /**
     * Résultats pour lesquels cette régularisation a un sens.
     *
     * @return list<InventoryLineResult>
     */
    public function appliesTo(): array
    {
        return match ($this) {
            self::Transfer => [InventoryLineResult::OtherAssignment, InventoryLineResult::OutOfScope],
            self::ConditionUpdate => [InventoryLineResult::ConditionMismatch],
            self::Disposal, self::Found => [InventoryLineResult::Missing],
            self::CreatedAsset => [InventoryLineResult::Unregistered],
        };
    }
}

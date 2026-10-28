<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** RG-45, RG-46 : résultat d'une ligne d'inventaire. */
enum InventoryLineResult: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Present = 'present';
    case Missing = 'missing';
    case OtherAssignment = 'other_assignment';
    case ConditionMismatch = 'condition_mismatch';
    case Justified = 'justified';
    case OutOfScope = 'out_of_scope';
    case Unregistered = 'unregistered';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'À vérifier',
            self::Present => 'Présent',
            self::Missing => 'Manquant',
            self::OtherAssignment => 'Autre affectation',
            self::ConditionMismatch => 'État différent',
            self::Justified => 'Justifié',
            self::OutOfScope => 'Présent hors périmètre',
            self::Unregistered => 'Non enregistré',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Present => 'success',
            self::Missing => 'danger',
            self::OtherAssignment, self::ConditionMismatch => 'warning',
            self::Justified, self::OutOfScope, self::Unregistered => 'info',
        };
    }

    /** Le bien a été vu sur place. */
    public function isFound(): bool
    {
        return in_array($this, [self::Present, self::OtherAssignment, self::ConditionMismatch, self::OutOfScope, self::Unregistered], true);
    }

    /**
     * Valeurs des résultats qui sont des écarts (alertes, rapport des écarts).
     *
     * @return list<string>
     */
    public static function discrepancyValues(): array
    {
        return array_values(array_map(
            fn (self $result): string => $result->value,
            array_filter(self::cases(), fn (self $result): bool => $result->isDiscrepancy()),
        ));
    }

    /** Écart à examiner dans le rapport. */
    public function isDiscrepancy(): bool
    {
        return in_array($this, [self::Missing, self::OtherAssignment, self::ConditionMismatch, self::OutOfScope, self::Unregistered], true);
    }
}

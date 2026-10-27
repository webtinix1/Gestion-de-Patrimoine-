<?php

namespace App\Actions\Depreciation;

/**
 * Résultat de la génération ou du recalcul du plan d'un bien.
 */
enum SyncOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Retired = 'retired';
    case Skipped = 'skipped';
    case Blocked = 'blocked';

    public function changed(): bool
    {
        return in_array($this, [self::Created, self::Updated, self::Retired], true);
    }
}

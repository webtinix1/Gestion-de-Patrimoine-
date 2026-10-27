<?php

namespace App\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * RG-71, RG-72 : entrée du journal, avec l'adresse IP du poste, jamais modifiée ni supprimée.
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (Activity $activity): void {
            $activity->properties = $activity->properties->put('ip', request()->ip());
        });

        static::updating(fn () => throw new LogicException('Le journal des opérations ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Le journal des opérations ne peut pas être supprimé.'));
    }

    public function ip(): ?string
    {
        $ip = $this->properties->get('ip');

        return is_string($ip) ? $ip : null;
    }
}

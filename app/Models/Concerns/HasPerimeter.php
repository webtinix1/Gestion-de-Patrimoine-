<?php

namespace App\Models\Concerns;

use App\Models\Scopes\PerimeterScope;
use Illuminate\Database\Eloquent\Model;

/**
 * RG-81 : le modèle est filtré par le périmètre de l'utilisateur connecté.
 *
 * @mixin Model
 */
trait HasPerimeter
{
    public static function bootHasPerimeter(): void
    {
        static::addGlobalScope(new PerimeterScope);
    }

    /**
     * Colonnes portant le site et le département. « department_nullable » : une ligne
     * sans département (salle de culte commune…) reste visible.
     *
     * @return array{site?: string, department?: string, department_nullable?: bool}
     */
    abstract public function perimeterColumns(): array;
}

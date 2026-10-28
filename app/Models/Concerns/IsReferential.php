<?php

namespace App\Models\Concerns;

use App\Exceptions\ReferentialInUseException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Élément de référentiel : il se désactive au lieu d'être supprimé dès qu'il est utilisé.
 *
 * @mixin Model
 */
trait IsReferential
{
    public static function bootIsReferential(): void
    {
        static::deleting(function (Model $model): void {
            /** @var static $model */
            if ($model->isInUse()) {
                throw ReferentialInUseException::make();
            }
        });
    }

    /**
     * Relations dont la présence d'un enregistrement rend l'élément « utilisé ».
     *
     * @return list<string>
     */
    abstract public function usageRelations(): array;

    public function isInUse(): bool
    {
        foreach ($this->usageRelations() as $relation) {
            if ($this->{$relation}()->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }
}

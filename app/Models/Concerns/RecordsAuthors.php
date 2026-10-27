<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renseigne created_by et updated_by avec l'utilisateur connecté.
 *
 * @mixin Model
 */
trait RecordsAuthors
{
    public static function bootRecordsAuthors(): void
    {
        static::creating(function (Model $model): void {
            $model->setAttribute('created_by', $model->getAttribute('created_by') ?? auth()->id());
            $model->setAttribute('updated_by', $model->getAttribute('updated_by') ?? auth()->id());
        });

        static::updating(function (Model $model): void {
            if (auth()->check()) {
                $model->setAttribute('updated_by', auth()->id());
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

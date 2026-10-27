<?php

namespace App\Filament\Support;

use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Champs de formulaire réutilisés.
 */
final class Fields
{
    /**
     * Code court en majuscules, unique dans sa table (Martian Mono à l'affichage).
     */
    public static function code(string $name = 'code', string $label = 'Code', bool $required = true, int $maxLength = 20): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required($required)
            ->maxLength($maxLength)
            ->regex('/^[A-Za-z0-9][A-Za-z0-9-]*$/')
            ->validationMessages(['regex' => 'Le code ne doit contenir que des lettres sans accent, des chiffres et des tirets.'])
            ->afterStateUpdated(fn (?string $state, TextInput $component) => $component->state(self::upper($state)))
            ->dehydrateStateUsing(fn (?string $state): ?string => self::upper($state))
            ->extraInputAttributes(['class' => 'pat-mono', 'style' => 'text-transform: uppercase'])
            ->unique(ignoreRecord: true);
    }

    public static function upper(?string $state): ?string
    {
        return filled($state) ? mb_strtoupper(trim($state)) : null;
    }

    /**
     * Refuse un élément désactivé dans un choix multiple (les listes ne proposent que les actifs).
     *
     * @param  class-string<Model>  $model
     */
    public static function onlyActive(string $model, string $message): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($model, $message): void {
            if ($model::query()->whereKey((array) $value)->where('is_active', false)->exists()) {
                $fail($message);
            }
        };
    }

    /**
     * Responsable choisi parmi les comptes actifs.
     */
    public static function manager(string $name = 'manager_id', string $label = 'Responsable'): Select
    {
        return Select::make($name)
            ->label($label)
            ->relationship(
                'manager',
                'last_name',
                fn (Builder $query) => $query->where('is_active', true)->orderBy('last_name')->orderBy('first_name'),
            )
            ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->fullName())
            ->searchable(['first_name', 'last_name', 'username'])
            ->preload();
    }
}

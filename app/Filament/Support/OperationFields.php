<?php

namespace App\Filament\Support;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\DocumentType;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Champs et messages communs aux écrans de prêts, de maintenance et de signalements. Aucune règle de gestion ici.
 */
final class OperationFields
{
    /**
     * Choix d'un ou plusieurs biens du périmètre de l'utilisateur (scope global), recherche par numéro ou désignation.
     *
     * @param  list<AssetStatus>|null  $statuses
     */
    public static function assetSelect(string $name = 'asset_id', string $label = 'Bien', bool $multiple = false, ?array $statuses = null): Select
    {
        $select = Select::make($name)
            ->label($label)
            ->searchable()
            ->multiple($multiple)
            ->getSearchResultsUsing(fn (string $search): array => self::assetOptions($search, $statuses));

        return $multiple
            ? $select->getOptionLabelsUsing(fn (array $values): array => self::assetLabels($values))
            : $select->getOptionLabelUsing(fn (mixed $value): ?string => self::assetLabels([$value])[$value] ?? null);
    }

    /**
     * @param  list<AssetStatus>|null  $statuses
     * @return array<int, string>
     */
    public static function assetOptions(string $search, ?array $statuses = null): array
    {
        return Asset::query()
            ->whereNull('disposed_at')
            ->when($statuses !== null, fn (Builder $query) => $query->whereIn('status', array_map(fn (AssetStatus $status): string => $status->value, $statuses ?? [])))
            ->where(fn (Builder $query) => $query->where('number', 'like', "%{$search}%")->orWhere('designation', 'like', "%{$search}%"))
            ->orderBy('number')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Asset $asset): array => [$asset->getKey() => self::assetLabel($asset)])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    public static function assetLabels(array $ids): array
    {
        return Asset::query()->whereKey($ids)->get()
            ->mapWithKeys(fn (Asset $asset): array => [$asset->getKey() => self::assetLabel($asset)])
            ->all();
    }

    public static function assetLabel(Asset $asset): string
    {
        return $asset->number.' · '.$asset->label();
    }

    public static function conditions(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(fn (): array => AssetCondition::query()->where('is_active', true)->orderBy('sort')->pluck('label', 'id')->all())
            ->native(false);
    }

    public static function users(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(fn (): array => User::query()->where('is_active', true)->orderBy('last_name')->orderBy('first_name')->get()
                ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->fullName()])
                ->all())
            ->searchable()
            ->native(false);
    }

    /**
     * Justificatif facultatif : le type de document n'est demandé que si un fichier est joint.
     *
     * @return list<FileUpload|Select>
     */
    public static function documents(string $label, string $defaultType): array
    {
        return [
            DocumentFields::files()
                ->label($label)
                ->required(false)
                ->live()
                ->columnSpanFull(),
            DocumentFields::type()
                ->default(fn (): ?int => DocumentType::query()->active()->where('label', $defaultType)->value('id'))
                ->required(fn (Get $get): bool => filled($get('files')))
                ->visible(fn (Get $get): bool => filled($get('files'))),
        ];
    }

    /**
     * Valeur d'un champ à choix : une liste fournie par une énumération rend l'énumération, pas sa valeur.
     */
    public static function value(mixed $state): mixed
    {
        return $state instanceof BackedEnum ? $state->value : $state;
    }

    /**
     * Garde les versions à l'ouverture de la fenêtre et rend le tableau de remplissage.
     *
     * @param  array<int, int>  $versions
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function held(object $livewire, array $versions, array $state): array
    {
        if (method_exists($livewire, 'holdVersions')) {
            $livewire->holdVersions($versions, replace: true);
        }

        return $state;
    }

    /**
     * Versions vues à l'ouverture, une par bien ; absentes, l'enregistrement est refusé (RG-86).
     *
     * @param  iterable<int|string>  $assetIds
     * @return array<int, int>
     */
    public static function heldVersions(object $livewire, iterable $assetIds): array
    {
        $versions = [];

        foreach ($assetIds as $id) {
            $version = method_exists($livewire, 'heldVersion') ? $livewire->heldVersion((int) $id) : null;

            if ($version === null) {
                throw ValidationException::withMessages(['version' => 'La fiche n\'a pas été relue à l\'ouverture de la fenêtre : fermez-la et rouvrez-la.']);
            }

            $versions[(int) $id] = $version;
        }

        return $versions;
    }

    public static function notifyFailure(string $title, Throwable $exception): void
    {
        $message = $exception instanceof ValidationException
            ? (string) collect($exception->errors())->flatten()->first()
            : $exception->getMessage();

        Notification::make()->danger()->title($title)->body($message)->persistent()->send();
    }
}

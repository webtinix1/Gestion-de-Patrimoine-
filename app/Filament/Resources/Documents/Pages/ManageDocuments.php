<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Actions\Documents\AttachDocuments;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Support\DocumentFields;
use App\Models\Asset;
use App\Models\Document;
use App\Models\DocumentType;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ManageDocuments extends ManageRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Ajouter des documents')
                ->icon(Heroicon::OutlinedPaperClip)
                ->modalHeading('Ajouter des documents')
                ->modalSubmitActionLabel('Ajouter')
                ->visible(fn (): bool => auth()->user()?->can('create', Document::class) ?? false)
                ->schema([
                    DocumentFields::files(),
                    DocumentFields::type(),
                    Select::make('asset_ids')
                        ->label('Biens à rattacher')
                        ->multiple()
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->assetQuery()
                            ->where(fn (Builder $query) => $query
                                ->where('number', 'like', "%{$search}%")
                                ->orWhere('designation', 'like', "%{$search}%"))
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->number.' · '.$asset->label()])
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values): array => $this->assetQuery()
                            ->whereKey($values)
                            ->get()
                            ->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->number.' · '.$asset->label()])
                            ->all()),
                ])
                ->action(function (array $data): void {
                    $assets = $this->assetQuery()->whereKey($data['asset_ids'])->get();

                    try {
                        if ($assets->count() !== count($data['asset_ids'])) {
                            throw new DomainException('Un des biens choisis est introuvable ou sorti du patrimoine.');
                        }

                        $documents = app(AttachDocuments::class)(
                            $data['files'],
                            DocumentType::query()->active()->findOrFail($data['document_type_id']),
                            $assets,
                        );
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title($documents->count() > 1 ? 'Documents ajoutés' : 'Document ajouté')
                        ->send();
                }),
        ];
    }

    /**
     * Biens en service dans le périmètre de l'utilisateur (RG-81, le scope s'applique seul).
     *
     * @return Builder<Asset>
     */
    private function assetQuery(): Builder
    {
        return Asset::query()->whereNull('disposed_at')->orderBy('number');
    }
}

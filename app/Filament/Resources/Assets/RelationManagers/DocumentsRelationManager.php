<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Actions\Documents\AttachDocuments;
use App\Actions\Documents\DetachDocument;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Filament\Support\DocumentFields;
use App\Models\Asset;
use App\Models\Document;
use App\Models\DocumentType;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * RG-14 : documents d'un bien. Utilisable sur la page de consultation (E06) ; les droits
 * et la lecture seule d'un bien sorti (E43) sont contrôlés action par action.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    protected static ?string $modelLabel = 'document';

    protected static ?string $pluralModelLabel = 'documents';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->modifyQueryUsing(fn ($query) => $query->with(['type', 'uploader']))
            ->defaultSort('documents.created_at', 'desc')
            ->emptyStateHeading('Aucun document')
            ->emptyStateDescription('Ajoutez une facture, une garantie ou une photo justificative.')
            ->columns([
                TextColumn::make('original_name')
                    ->label('Nom')
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('type.label')
                    ->label('Type'),
                TextColumn::make('size')
                    ->label('Taille')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (Document $record): string => $record->humanSize()),
                TextColumn::make('created_at')
                    ->label('Ajouté le')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('uploader')
                    ->label('Par')
                    ->getStateUsing(fn (Document $record): ?string => $record->uploader?->fullName())
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('attach')
                    ->label('Ajouter des documents')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->modalHeading('Ajouter des documents')
                    ->modalSubmitActionLabel('Ajouter')
                    ->schema([
                        DocumentFields::files(),
                        DocumentFields::type(),
                    ])
                    ->visible(fn (): bool => $this->canEditDocuments(PermissionAbility::Create))
                    ->action(function (array $data): void {
                        try {
                            $documents = app(AttachDocuments::class)(
                                $data['files'],
                                DocumentType::query()->active()->findOrFail($data['document_type_id']),
                                [$this->asset()],
                            );
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title($documents->count() > 1 ? 'Documents ajoutés' : 'Document ajouté')
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Ouvrir')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Document $record): string => $record->fileUrl(), shouldOpenInNewTab: true),
                Action::make('download')
                    ->label('Télécharger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (Document $record): string => $record->fileUrl(download: true)),
                Action::make('detach')
                    ->label('Détacher')
                    ->icon(Heroicon::OutlinedLinkSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Détacher ce document ?')
                    ->modalDescription('Le fichier est conservé : seul son lien avec ce bien est retiré.')
                    ->visible(fn (): bool => $this->canEditDocuments(PermissionAbility::Update))
                    ->action(function (Document $record): void {
                        try {
                            app(DetachDocument::class)($record, $this->asset());
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Document détaché')->send();
                    }),
            ]);
    }

    private function canEditDocuments(PermissionAbility $ability): bool
    {
        return ! $this->asset()->isDisposed() && (auth()->user()?->can(PermissionModule::Documents->permission($ability)) ?? false);
    }

    private function asset(): Asset
    {
        /** @var Asset $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }
}

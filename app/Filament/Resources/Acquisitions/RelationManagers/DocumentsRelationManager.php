<?php

namespace App\Filament\Resources\Acquisitions\RelationManagers;

use App\Actions\Acquisitions\AttachAcquisitionDocuments;
use App\Actions\Acquisitions\DetachAcquisitionDocument;
use App\Enums\AcquisitionStatus;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Filament\Support\DocumentFields;
use App\Models\Acquisition;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * RG-65 : justificatifs d'une acquisition. Ajout et retrait réservés au brouillon.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Justificatifs';

    protected static ?string $modelLabel = 'justificatif';

    protected static ?string $pluralModelLabel = 'justificatifs';

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
            ->emptyStateHeading('Aucun justificatif')
            ->emptyStateDescription('Joignez la facture, le bon de livraison ou le procès-verbal.')
            ->columns([
                TextColumn::make('original_name')->label('Nom')->weight('semibold')->searchable(),
                TextColumn::make('type.label')->label('Type'),
                TextColumn::make('size')
                    ->label('Taille')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (Document $record): string => $record->humanSize()),
                TextColumn::make('created_at')->label('Ajouté le')->date('d/m/Y')->sortable(),
                TextColumn::make('uploader')
                    ->label('Par')
                    ->getStateUsing(fn (Document $record): ?string => $record->uploader?->fullName())
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('attach')
                    ->label('Joindre facture, bon de livraison ou PV')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->modalHeading('Ajouter des justificatifs')
                    ->modalSubmitActionLabel('Ajouter')
                    ->schema([DocumentFields::files(), DocumentFields::type()])
                    ->visible(fn (): bool => $this->canEditDocuments(PermissionAbility::Create))
                    ->action(function (array $data): void {
                        try {
                            $documents = app(AttachAcquisitionDocuments::class)(
                                $data['files'],
                                DocumentType::query()->active()->findOrFail($data['document_type_id']),
                                $this->acquisition(),
                            );
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title($documents->count() > 1 ? 'Justificatifs ajoutés' : 'Justificatif ajouté')
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
                    ->modalHeading('Détacher ce justificatif ?')
                    ->modalDescription('Le fichier est conservé : seul son lien avec cette acquisition est retiré.')
                    ->visible(fn (): bool => $this->canEditDocuments(PermissionAbility::Update))
                    ->action(function (Document $record): void {
                        try {
                            app(DetachAcquisitionDocument::class)($record, $this->acquisition());
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Justificatif détaché')->send();
                    }),
            ]);
    }

    private function canEditDocuments(PermissionAbility $ability): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && Acquisition::query()->whereKey($this->acquisition()->getKey())->where('status', AcquisitionStatus::Draft)->exists()
            && $user->can(PermissionModule::Acquisitions->permission(PermissionAbility::Update))
            && $user->can(PermissionModule::Documents->permission($ability));
    }

    private function acquisition(): Acquisition
    {
        /** @var Acquisition $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }
}

<?php

namespace App\Filament\Resources\Documents;

use App\Actions\Documents\DeleteDocument;
use App\Filament\Resources\Documents\Pages\ManageDocuments;
use App\Models\Document;
use App\Models\DocumentType;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * E28 : documents de tous les biens visibles, avec ajout multiple et rattachement.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Pilotage';

    protected static ?int $navigationSort = 3;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'document';

    protected static ?string $pluralModelLabel = 'Documents';

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $slug = 'documents';

    protected static ?string $recordTitleAttribute = 'original_name';

    /**
     * RG-66 : seuls les documents d'au moins un objet visible apparaissent.
     *
     * @return Builder<Document>
     */
    public static function getEloquentQuery(): Builder
    {
        return Document::query()->visible();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'uploader', 'assets']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('original_name')
                    ->label('Nom')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('documents.original_name', 'like', "%{$search}%")
                            ->orWhereHas('assets', fn (Builder $assets) => $assets->where('assets.number', 'like', "%{$search}%")),
                    )),
                TextColumn::make('type.label')
                    ->label('Type'),
                TextColumn::make('assets.number')
                    ->label('Lié à')
                    ->badge()
                    ->color('gray')
                    ->fontFamily('mono')
                    ->limitList(3)
                    ->placeholder('—'),
                TextColumn::make('size')
                    ->label('Taille')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (Document $record): string => $record->humanSize())
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Ajouté le')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('uploader')
                    ->label('Par')
                    ->getStateUsing(fn (Document $record): ?string => $record->uploader?->fullName())
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('document_type_id')
                    ->label('Type')
                    ->options(fn (): array => DocumentType::query()->orderBy('label')->pluck('label', 'id')->all()),
                Filter::make('period')
                    ->label('Période')
                    ->schema([
                        DatePicker::make('from')->label('Du'),
                        DatePicker::make('until')->label('Au'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('documents.created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('documents.created_at', '<=', $date))),
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
                Action::make('delete')
                    ->label('Supprimer')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->modalHeading('Supprimer ce document ?')
                    ->modalDescription('Ce document n\'est plus rattaché à aucun objet. Le fichier sera effacé du serveur.')
                    ->action(function (Document $record): void {
                        try {
                            app(DeleteDocument::class)($record);
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Document supprimé')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocuments::route('/'),
        ];
    }
}

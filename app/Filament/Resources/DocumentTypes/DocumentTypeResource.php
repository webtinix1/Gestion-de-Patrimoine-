<?php

namespace App\Filament\Resources\DocumentTypes;

use App\Filament\Clusters\Referentials\ReferentialsCluster;
use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Filament\Support\Referentials;
use App\Models\DocumentType;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * RG-65 : types de documents, liste paramétrable.
 */
class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static ?string $cluster = ReferentialsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 4;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'type de document';

    protected static ?string $pluralModelLabel = 'Types de documents';

    protected static ?string $navigationLabel = 'Types de documents';

    protected static ?string $slug = 'types-documents';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->label('Libellé')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                Referentials::activeToggle(),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->columns([
                TextColumn::make('label')
                    ->label('Type de document')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),
                Referentials::activeColumn(),
            ])
            ->filters([
                Referentials::activeFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(Referentials::recordActions()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentTypes::route('/'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Departments\RelationManagers;

use App\Filament\Support\Fields;
use App\Filament\Support\Referentials;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    protected static ?string $title = 'Services';

    protected static ?string $modelLabel = 'service';

    protected static ?string $pluralModelLabel = 'services';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom du service')
                    ->required()
                    ->maxLength(255),
                TextInput::make('code')
                    ->label('Code')
                    ->maxLength(20)
                    ->regex('/^[A-Za-z0-9][A-Za-z0-9-]*$/')
                    ->validationMessages(['regex' => 'Le code ne doit contenir que des lettres sans accent, des chiffres et des tirets.'])
                    ->afterStateUpdated(fn (?string $state, TextInput $component) => $component->state(Fields::upper($state)))
                    ->dehydrateStateUsing(fn (?string $state): ?string => Fields::upper($state))
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('department_id', $this->getOwnerRecord()->getKey()),
                    ),
                Referentials::activeToggle(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Service')
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily('mono'),
                Referentials::activeColumn(),
            ])
            ->filters([
                Referentials::activeFilter(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(Referentials::recordActions()),
            ]);
    }
}

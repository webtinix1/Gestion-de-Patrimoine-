<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Enums\RoomType;
use App\Filament\Support\Fields;
use App\Filament\Support\Referentials;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Local')
                    ->description('Lieu physique où se trouvent les biens. Chaque local appartient à un site.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom du local')
                            ->placeholder('Ex. Salle de répétition')
                            ->required()
                            ->maxLength(255),
                        Fields::code()
                            ->placeholder('Ex. SIE-REP'),
                        Select::make('site_id')
                            ->label('Site')
                            ->relationship('site', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                            ->required()
                            ->preload(),
                        Select::make('type')
                            ->label('Type')
                            ->options(RoomType::class)
                            ->default(RoomType::Office)
                            ->required(),
                        Select::make('department_id')
                            ->label('Département associé')
                            ->placeholder('Aucun')
                            ->relationship('department', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                            ->preload(),
                        Referentials::activeToggle(),
                    ]),
            ]);
    }
}

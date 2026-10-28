<?php

namespace App\Filament\Resources\Sites\Schemas;

use App\Filament\Support\Fields;
use App\Filament\Support\Referentials;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Site')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom du site')
                            ->required()
                            ->maxLength(255),
                        Fields::code(),
                        TextInput::make('address')
                            ->label('Adresse ou localisation')
                            ->maxLength(255),
                        Fields::manager(),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Referentials::activeToggle('Site actif'),
                    ]),
            ]);
    }
}

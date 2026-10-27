<?php

namespace App\Filament\Resources\Departments\Schemas;

use App\Filament\Support\Fields;
use App\Filament\Support\Referentials;
use App\Models\Site;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Département')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255),
                        Fields::code(),
                        Select::make('sites')
                            ->label('Sites de rattachement')
                            ->helperText('Un département peut exister sur plusieurs sites.')
                            ->relationship('sites', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('name'))
                            ->multiple()
                            ->preload()
                            ->rule(Fields::onlyActive(Site::class, 'Un site inactif ne peut pas être choisi.')),
                        Fields::manager(),
                        Referentials::activeToggle(),
                    ]),
            ]);
    }
}

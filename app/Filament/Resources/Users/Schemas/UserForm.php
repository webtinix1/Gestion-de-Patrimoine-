<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Support\Fields;
use App\Models\Department;
use App\Models\Role;
use App\Models\Site;
use App\Support\DefaultRoles;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Compte')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('first_name')->label('Prénom')->required()->maxLength(255),
                        TextInput::make('last_name')->label('Nom')->required()->maxLength(255),
                        TextInput::make('username')
                            ->label('Identifiant')
                            ->helperText('Minuscules, chiffres, point, tiret. Ex. a.okemba')
                            ->required()
                            ->maxLength(50)
                            ->regex('/^[A-Za-z0-9._-]+$/')
                            ->validationMessages(['regex' => 'L\'identifiant ne doit contenir que des lettres sans accent, des chiffres, des points et des tirets.'])
                            ->afterStateUpdated(fn (?string $state, TextInput $component) => $component->state(filled($state) ? mb_strtolower(trim($state)) : null))
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtolower(trim($state)) : null)
                            ->extraInputAttributes(['class' => 'pat-mono'])
                            ->unique(ignoreRecord: true),
                        TextInput::make('email')->label('E-mail')->email()->maxLength(255)->unique(ignoreRecord: true),
                        TextInput::make('phone')->label('Téléphone')->tel()->maxLength(30),
                        TextInput::make('job_title')->label('Fonction')->maxLength(255),
                        Text::make('Un mot de passe provisoire sera généré à la création. À communiquer en main propre : il devra être changé à la première connexion.')
                            ->color('gray')
                            ->columnSpanFull()
                            ->visibleOn('create'),
                        Toggle::make('is_active')
                            ->label('Compte actif')
                            ->helperText('Un compte désactivé ne peut plus se connecter et garde son historique.')
                            ->default(true)
                            ->disabled(fn ($record): bool => $record !== null && $record->is(auth()->user()))
                            ->hiddenOn('create'),
                    ]),
                Section::make('Rôle et périmètre')
                    ->description('Le périmètre limite toutes les listes, recherches, rapports et exports. Laisser vide pour « tous ».')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('role')
                            ->label('Rôle')
                            ->options(fn (): array => Role::query()->orderBy('id')->pluck('name', 'name')->all())
                            ->default(DefaultRoles::MANAGER)
                            ->required()
                            ->columnSpanFull(),
                        Select::make('sites')
                            ->label('Sites accessibles')
                            ->placeholder('Tous les sites')
                            ->options(fn (): array => Site::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->multiple()
                            ->rule(Fields::onlyActive(Site::class, 'Un site inactif ne peut pas être choisi.')),
                        Select::make('departments')
                            ->label('Départements accessibles')
                            ->placeholder('Tous les départements')
                            ->options(fn (): array => Department::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->multiple()
                            ->rule(Fields::onlyActive(Department::class, 'Un département inactif ne peut pas être choisi.')),
                    ]),
            ]);
    }
}

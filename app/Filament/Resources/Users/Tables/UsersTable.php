<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserActions;
use App\Models\Department;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->columns([
                TextColumn::make('last_name')
                    ->label('Utilisateur')
                    ->formatStateUsing(fn (User $record): string => $record->fullName())
                    ->description(fn (User $record): ?string => $record->job_title)
                    ->weight('semibold')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('username')
                    ->label('Identifiant')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Rôle')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('perimeter')
                    ->label('Périmètre')
                    ->state(fn (User $record): string => self::perimeter($record)),
                TextColumn::make('last_login_at')
                    ->label('Dernière connexion')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Jamais')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->state(fn (User $record): string => match (true) {
                        ! $record->is_active => 'Désactivé',
                        $record->isLocked() => 'Bloqué',
                        $record->must_change_password => 'Mot de passe provisoire',
                        default => 'Actif',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Actif' => 'success',
                        'Bloqué' => 'danger',
                        'Mot de passe provisoire' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Rôle')
                    ->relationship('roles', 'name')
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->placeholder('Tous')
                    ->trueLabel('Actifs')
                    ->falseLabel('Désactivés'),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    UserActions::resetPassword(),
                    UserActions::deactivate(),
                    UserActions::reactivate(),
                ]),
            ]);
    }

    private static function perimeter(User $user): string
    {
        $siteIds = $user->perimeterSiteIds();
        $departmentIds = $user->perimeterDepartmentIds();

        if ($siteIds === null && $departmentIds === null) {
            return 'Tous';
        }

        $parts = [];

        if ($siteIds !== null) {
            $parts[] = Site::query()->withoutGlobalScopes()->whereKey($siteIds)->orderBy('name')->pluck('name')->join(', ');
        }

        if ($departmentIds !== null) {
            $parts[] = Department::query()->withoutGlobalScopes()->whereKey($departmentIds)->orderBy('name')->pluck('name')->join(', ');
        }

        return implode(' · ', $parts);
    }
}

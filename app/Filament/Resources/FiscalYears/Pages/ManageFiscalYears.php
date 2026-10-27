<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Actions\FiscalYears\CreateFiscalYear;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use App\Models\FiscalYear;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;

class ManageFiscalYears extends ManageRecords
{
    protected static string $resource = FiscalYearResource::class;

    public function getSubheading(): string
    {
        return '* Valeur prévisionnelle tant que l\'exercice n\'est pas clôturé. Exercice = année civile (Q5).';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createFiscalYear')
                ->label('Nouvel exercice')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => FiscalYearResource::canCreate())
                ->modalHeading('Nouvel exercice')
                ->modalDescription('L\'exercice couvre l\'année civile, du 01/01 au 31/12. Il est créé « préparé ».')
                ->fillForm(fn (): array => ['year' => ((int) FiscalYear::query()->max('year') ?: now()->year) + 1])
                ->schema([
                    TextInput::make('year')->label('Année')->integer()->minValue(1990)->maxValue(2100)->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $year = app(CreateFiscalYear::class)((int) $data['year']);
                        Notification::make()->title('Exercice '.$year->year.' créé')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Création impossible')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                        $action->halt();
                    }
                }),
        ];
    }
}

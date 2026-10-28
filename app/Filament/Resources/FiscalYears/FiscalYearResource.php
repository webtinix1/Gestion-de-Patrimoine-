<?php

namespace App\Filament\Resources\FiscalYears;

use App\Actions\FiscalYears\CloseFiscalYear;
use App\Actions\FiscalYears\OpenFiscalYear;
use App\Filament\Resources\FiscalYears\Pages\ManageFiscalYears;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\DepreciationRegister;
use App\Services\FiscalYearClosing\AttentionPoint;
use App\Services\FiscalYearClosing\FiscalYearAttentionPoints;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * E33 : exercices comptables. Clôture avec points d'attention (RG-41 à RG-43), irréversible.
 */
class FiscalYearResource extends Resource
{
    protected static ?string $model = FiscalYear::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Immobilisations';

    protected static ?int $navigationSort = 3;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $modelLabel = 'exercice comptable';

    protected static ?string $pluralModelLabel = 'Exercices comptables';

    protected static ?string $navigationLabel = 'Exercices comptables';

    protected static ?string $slug = 'exercices';

    protected static ?string $recordTitleAttribute = 'label';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->description('Un exercice clôturé fige ses dotations. Les plans d\'amortissement validés sont conservés.')
            ->columns([
                TextColumn::make('label')->label('Exercice')->weight('bold'),
                TextColumn::make('starts_on')
                    ->label('Période')
                    ->state(fn (FiscalYear $record): string => $record->starts_on->format('d/m').' au '.$record->ends_on->format('d/m/Y')),
                TextColumn::make('amount')
                    ->label('Dotations')
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->state(fn (FiscalYear $record): string => Money::format(self::totals($record)['amount'])),
                TextColumn::make('net')
                    ->label('VNC de clôture')
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->state(fn (FiscalYear $record): string => Money::format(self::totals($record)['net']).($record->isClosed() ? '' : ' *')),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('closed_at')
                    ->label('Clôturé par')
                    ->state(fn (FiscalYear $record): ?string => $record->closed_at === null ? null : trim(($record->closer?->fullName() ?? '—').' · '.$record->closed_at->format('d/m/Y')))
                    ->placeholder('·'),
            ])
            ->recordActions([
                self::openAction(),
                self::closeAction(),
            ])
            ->emptyStateHeading('Aucun exercice comptable');
    }

    /**
     * @return array{amount: int, net: int}
     */
    private static function totals(FiscalYear $fiscalYear): array
    {
        $register = app(DepreciationRegister::class);
        $summary = $register->summary($register->query($fiscalYear->year), $fiscalYear->year)['totals'];

        return ['amount' => $summary['amount'], 'net' => $summary['net']];
    }

    private static function openAction(): Action
    {
        return Action::make('open')
            ->label('Ouvrir')
            ->icon('heroicon-o-lock-open')
            ->visible(fn (FiscalYear $record): bool => auth()->user()?->can('open', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (FiscalYear $record): string => 'Ouvrir l\'exercice '.$record->year)
            ->action(function (FiscalYear $record): void {
                app(OpenFiscalYear::class)($record);
                Notification::make()->title('Exercice '.$record->year.' ouvert')->success()->send();
            });
    }

    private static function closeAction(): Action
    {
        return Action::make('close')
            ->label('Clôturer')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->visible(fn (FiscalYear $record): bool => auth()->user()?->can('close', $record) ?? false)
            ->disabled(fn (FiscalYear $record): bool => now()->toDateString() < $record->ends_on->toDateString())
            ->tooltip(fn (FiscalYear $record): ?string => now()->toDateString() < $record->ends_on->toDateString()
                ? 'Disponible à partir du '.$record->ends_on->format('d/m/Y').' (RG-41).'
                : null)
            ->modalHeading(fn (FiscalYear $record): string => 'Clôturer l\'exercice '.$record->year)
            ->modalDescription('La clôture fige les dotations de l\'exercice, valide les plans concernés et ouvre l\'exercice suivant. Elle ne peut pas être annulée.')
            ->modalSubmitActionLabel('Clôturer définitivement')
            ->schema(fn (FiscalYear $record): array => [
                Text::make(fn (): HtmlString => self::attentionPointsHtml($record)),
                Checkbox::make('acknowledged')
                    ->label('J\'ai pris connaissance des points d\'attention et je confirme la clôture, irréversible.')
                    ->accepted(),
            ])
            ->action(function (FiscalYear $record, array $data, Action $action): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(CloseFiscalYear::class)($record, $user, (bool) ($data['acknowledged'] ?? false));
                } catch (ValidationException $exception) {
                    Notification::make()->title('Clôture impossible')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->persistent()->send();
                    $action->halt();
                }

                Notification::make()->title('Exercice '.$record->year.' clôturé')->body('L\'exercice '.($record->year + 1).' est ouvert.')->success()->send();
            });
    }

    private static function attentionPointsHtml(FiscalYear $fiscalYear): HtmlString
    {
        $items = array_map(function (AttentionPoint $point): string {
            [$symbol, $background, $color] = $point->needsConfirmation() ? ['!', '#FBF0D9', '#8A5A00'] : ['✓', '#E4F2EA', '#1E6E43'];

            return '<li style="display:flex;gap:10px;align-items:flex-start;padding:6px 0">'
                .'<span style="width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px;font-weight:700;background:'.$background.';color:'.$color.'">'.$symbol.'</span>'
                .'<span>'.e($point->label).'</span></li>';
        }, app(FiscalYearAttentionPoints::class)->for($fiscalYear));

        return new HtmlString('<p class="text-sm font-semibold">Points d\'attention (RG-42)</p><ul class="text-sm">'.implode('', $items).'</ul>'
            .'<p class="text-sm text-gray-600">Ils n\'empêchent pas la clôture mais doivent être confirmés.</p>');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFiscalYears::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', FiscalYear::class) ?? false;
    }
}

<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Services\DepreciationPlanReport;
use App\Services\FiscalYearCalendar;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;

/**
 * E06 : compteurs d'immobilisation (valeur d'origine, cumul, VNC, durée restante) et onglet
 * Amortissement de la fiche, avec le lien vers le plan complet (E31).
 */
class AssetDepreciationTab
{
    public static function counters(): Section
    {
        return Section::make()
            ->columns(4)
            ->visible(fn (Asset $record): bool => self::canView() && self::schedule($record) !== null)
            ->schema(fn (Asset $record): array => collect(self::counterValues($record))
                ->map(fn (string $value, string $label): TextEntry => TextEntry::make('depreciation_counter_'.md5($label))
                    ->label($label)
                    ->state($value)
                    ->fontFamily('mono')
                    ->weight('bold'))
                ->values()
                ->all());
    }

    public static function tab(): Tab
    {
        return Tab::make('Amortissement')
            ->visible(fn (): bool => self::canView())
            ->schema([
                Text::make(fn (Asset $record): HtmlString => self::content($record)),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function counterValues(Asset $record): array
    {
        $year = (new FiscalYearCalendar)->current()->year ?? now()->year;

        return app(DepreciationPlanReport::class)->counters($record, self::schedule($record), $year, CarbonImmutable::today());
    }

    private static function content(Asset $record): HtmlString
    {
        $report = app(DepreciationPlanReport::class)->build($record);
        $url = AssetResource::getUrl('depreciation', ['record' => $record]);

        if ($report['schedule'] === null) {
            $message = $record->is_depreciable
                ? 'Aucun plan : la date de mise en service, la durée ou le prix d\'acquisition manque.'
                : 'Bien non amortissable : il est suivi sans plan d\'amortissement.';

            return new HtmlString('<p class="text-sm text-gray-600">'.e($message).'</p>');
        }

        return new HtmlString(view('filament.depreciation.lines-table', ['report' => $report, 'full' => false])->render()
            .'<p class="mt-4"><a class="text-sm font-semibold text-primary-600 underline" href="'.e($url).'">Ouvrir le plan d\'amortissement complet</a></p>');
    }

    private static function schedule(Asset $record): ?DepreciationSchedule
    {
        return app(DepreciationPlanReport::class)->current($record);
    }

    private static function canView(): bool
    {
        return auth()->user()?->can('viewAny', DepreciationSchedule::class) ?? false;
    }
}

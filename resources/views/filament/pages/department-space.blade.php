@php
    use App\Filament\Pages\Alerts;
    use App\Filament\Pages\Reports;
    use App\Filament\Pages\SearchByCode;
    use App\Filament\Resources\AssetReports\AssetReportResource;
    use App\Filament\Resources\Assets\AssetResource;
    use App\Filament\Resources\Inventories\InventoryResource;
    use App\Filament\Resources\Loans\LoanResource;

    $d = $this->getData();
    $c = $d['counters'];
    $names = $d['departments']->pluck('name')->join(', ');
    $services = $d['departments']->flatMap(fn ($department) => $department->services->pluck('name'))->unique()->take(6)->join(', ');
    $sites = $d['sites']->pluck('name')->join(' et ');
    $card = 'background: #fff; border: 1px solid #D7DBE1; padding: 18px 20px; display: flex; flex-direction: column; gap: 4px';
    $k = 'font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: #686868';
    $shortcut = 'display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px; border-radius: 6px; font-size: 14px; font-weight: 600; text-decoration: none; border: 1px solid #8F959F; background: #fff; color: #2E2E2E';
    $inventory = $d['next_inventory'];
@endphp
<x-filament-panels::page>
    <div style="display: flex; flex-direction: column">
        <div style="background: #0B5D6B; color: #fff; padding: 26px 28px 64px; display: flex; flex-direction: column; gap: 6px">
            <span style="font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: #CFE3E6">Responsable de département</span>
            <h2 style="margin: 0; font-size: 30px; line-height: 1.05; font-weight: 800; text-transform: uppercase">{{ $d['departments']->count() > 1 ? 'Départements' : 'Département' }}<br>{{ $names ?: '—' }}</h2>
            <p style="margin: 0; font-size: 14px; color: #E3F0F2">{{ $sites }}@if ($services) · services {{ $services }}@endif</p>
        </div>
        <section aria-label="Indicateurs du département" style="margin-top: -40px; padding: 0 28px; display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr))">
            <div style="background: #262626; color: #fff; padding: 18px 20px; display: flex; flex-direction: column; gap: 4px"><span style="font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: #B9BFC8">Biens affectés</span><span data-figure="assigned" style="font-size: 28px; font-weight: 800">{{ $c['assigned'] }}</span></div>
            <div style="{{ $card }}"><span style="{{ $k }}">En service</span><span data-figure="in_service" style="font-size: 28px; font-weight: 800">{{ $c['in_service'] }}</span></div>
            <div style="{{ $card }}; border-left: 0"><span style="{{ $k }}">En maintenance</span><span data-figure="in_maintenance" style="font-size: 28px; font-weight: 800; color: #8A5A00">{{ $c['in_maintenance'] }}</span></div>
            <div style="{{ $card }}; border-left: 0"><span style="{{ $k }}">Prêtés</span><span data-figure="on_loan" style="font-size: 28px; font-weight: 800">{{ $c['on_loan'] }}</span></div>
            <div style="{{ $card }}; border-left: 0"><span style="{{ $k }}">Hors service</span><span data-figure="out_of_service" style="font-size: 28px; font-weight: 800; color: #A8261B">{{ $c['out_of_service'] }}</span></div>
        </section>
    </div>

    <p role="note" style="margin: 0; font-size: 14px; color: #3A3A3A; background: #E6EBF8; padding: 12px 16px">Vous voyez uniquement les biens de votre périmètre. Pour tout changement d'affectation ou d'état, faites un signalement : le gestionnaire du patrimoine le traite.</p>

    <nav aria-label="Raccourcis" style="display: flex; flex-wrap: wrap; gap: 10px">
        @if (SearchByCode::canAccess())
            <a href="{{ SearchByCode::getUrl() }}" style="{{ $shortcut }}">Scanner un bien</a>
        @endif
        @can('viewAny', \App\Models\Asset::class)
            <a href="{{ AssetResource::getUrl('index') }}" style="{{ $shortcut }}">Biens du département</a>
        @endcan
        @can('viewAny', \App\Models\Loan::class)
            <a href="{{ LoanResource::getUrl('index') }}" style="{{ $shortcut }}">Prêts</a>
        @endcan
        @can('viewAny', \App\Models\Inventory::class)
            <a href="{{ InventoryResource::getUrl('index') }}" style="{{ $shortcut }}">Mes inventaires</a>
        @endcan
        @can('viewAny', \App\Models\AssetReport::class)
            <a href="{{ AssetReportResource::getUrl('index') }}" style="{{ $shortcut }}">Mes signalements</a>
        @endcan
        @if (Reports::canAccess())
            <a href="{{ Reports::getUrl() }}" style="{{ $shortcut }}">Rapports</a>
        @endif
        <a href="{{ Alerts::getUrl() }}" style="{{ $shortcut }}">Alertes @if ($d['unread_alerts'] > 0)<span data-figure="unread_alerts" style="margin-left: 6px; background: #A8261B; color: #fff; padding: 2px 8px; font-size: 12px">{{ $d['unread_alerts'] }}</span>@endif</a>
    </nav>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px">
        @if ($d['next_inventory'] !== null || auth()->user()?->can('viewAny', \App\Models\Inventory::class))
            <x-filament::section heading="Prochain inventaire">
                @if ($inventory)
                    <div style="display: flex; flex-direction: column; gap: 10px">
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap"><x-filament::badge :color="$inventory->status->getColor()">{{ $inventory->status->getLabel() }}</x-filament::badge><span class="pat-mono" style="font-weight: 600; color: #0B5D6B">{{ $inventory->reference }}</span></div>
                        <p style="margin: 0; font-size: 14px">{{ $inventory->scopeLabel() }} · du {{ $inventory->periodLabel() }}.@if ($inventory->isDesignated(auth()->user())) Vous y êtes désigné.@endif</p>
                        @if ($inventory->isInProgress() && auth()->user()?->can('enter', $inventory))
                            <x-filament::button tag="a" :href="InventoryResource::getUrl('enter', ['record' => $inventory])" style="align-self: flex-start">Participer à la saisie</x-filament::button>
                        @endif
                    </div>
                @else
                    <span style="color: #686868">Aucun inventaire planifié ou en cours.</span>
                @endif
            </x-filament::section>
        @endif

        @if ($d['my_reports'] !== null)
            <x-filament::section heading="Mes signalements">
                <div style="display: flex; flex-direction: column">
                    @forelse ($d['my_reports'] as $report)
                        <div style="display: flex; gap: 10px; align-items: center; font-size: 14px; padding: 8px 0; border-top: 1px solid #E9ECF0">
                            <x-filament::badge :color="$report->status->getColor()">{{ $report->status->getLabel() }}</x-filament::badge>
                            <span style="flex: 1"><span class="pat-mono" style="font-weight: 600; color: #0B5D6B">{{ $report->asset->number }}</span> {{ $report->asset->label() }}</span>
                            <span style="font-size: 12px; color: #686868">{{ $report->created_at?->format('d/m') }}</span>
                        </div>
                    @empty
                        <span style="color: #686868">Aucun signalement.</span>
                    @endforelse
                </div>
            </x-filament::section>
        @endif
    </div>

    <x-filament::section heading="Biens du département">
        <div style="overflow-x: auto">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px">
                <thead>
                    <tr style="text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #686868"><th style="padding: 8px 12px">N°</th><th style="padding: 8px 12px">Désignation</th><th style="padding: 8px 12px">Local</th><th style="padding: 8px 12px">État</th><th style="padding: 8px 12px">Statut</th></tr>
                </thead>
                <tbody>
                    @forelse ($d['assets'] as $asset)
                        <tr style="border-top: 1px solid #E9ECF0">
                            <td style="padding: 10px 12px"><a class="pat-mono" style="font-weight: 600; color: #0B5D6B" href="{{ AssetResource::getUrl('view', ['record' => $asset]) }}">{{ $asset->number }}</a></td>
                            <td style="padding: 10px 12px">{{ $asset->label() }}</td>
                            <td style="padding: 10px 12px">{{ $asset->room?->name ?? '—' }}</td>
                            <td style="padding: 10px 12px">{{ $asset->condition->label ?? '—' }}</td>
                            <td style="padding: 10px 12px"><x-filament::badge :color="$asset->status->getColor()">{{ $asset->status->getLabel() }}</x-filament::badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="padding: 16px 12px; color: #686868">Aucun bien dans votre périmètre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding-top: 12px; font-size: 13px; color: #686868">1 à {{ $d['assets']->count() }} sur {{ $d['assets_total'] }} · <a style="color: #0B5D6B" href="{{ AssetResource::getUrl('index') }}">Voir la liste complète</a></div>
    </x-filament::section>
</x-filament-panels::page>

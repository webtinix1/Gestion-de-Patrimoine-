@php
    use App\Enums\AssetStatus;
    use App\Filament\Pages\Alerts;
    use App\Filament\Pages\Reports;
    use App\Filament\Resources\Assets\AssetResource;
    use App\Filament\Resources\Inventories\InventoryResource;
    use App\Filament\Resources\Loans\LoanResource;
    use App\Filament\Resources\Maintenance\MaintenanceResource;
    use App\Filament\Resources\Movements\MovementResource;
    use App\Support\Money;

    $d = $this->getData();
    $money = fn (?int $value): string => Money::format($value);
    $c = $d['counters'];
    $hasAssets = $d['values']['acquisition'] !== null;
    $s = $c['statuses'];
    $total = max(1, (int) $c['total']);
    $pct = fn (int $n): string => rtrim(rtrim(number_format($n * 100 / $total, 1, '.', ''), '0'), '.');
    $card = 'background: #fff; border: 1px solid #D7DBE1; border-radius: 12px; padding: 16px 18px; display: flex; flex-direction: column; gap: 6px; text-decoration: none; color: #2E2E2E';
    $label = 'font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: #686868';
    $canAssets = auth()->user()?->can('viewAny', \App\Models\Asset::class);
    $heroButton = 'display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px; border-radius: 6px; font-size: 14px; font-weight: 600; text-decoration: none; border: 1px solid #CFE3E6; color: #fff';
@endphp
<x-filament-panels::page>
    {{-- Bandeau titre --}}
    <div style="display: flex; flex-direction: column">
        <div style="background: #0B5D6B; color: #fff; padding: 28px 28px 72px; display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px">
            <div style="display: flex; flex-direction: column; gap: 6px">
                <span style="font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: #CFE3E6">Patrimoine · Exercice {{ $d['year'] }}</span>
                <h2 style="margin: 0; font-size: 30px; line-height: 1.05; font-weight: 800; text-transform: uppercase">Bonjour<br>{{ $this->getGreeting() }}</h2>
                <p style="margin: 0; font-size: 14px; color: #E3F0F2">Situation du patrimoine au {{ now()->translatedFormat('j F Y') }}</p>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 10px">
                @can('viewAny', \App\Models\LabelTemplate::class)
                    <a href="{{ \App\Filament\Pages\LabelPrinting::getUrl() }}" style="{{ $heroButton }}">Imprimer des étiquettes</a>
                @endcan
                @can('create', \App\Models\Inventory::class)
                    <a href="{{ InventoryResource::getUrl('create') }}" style="{{ $heroButton }}">Lancer un inventaire</a>
                @endcan
                @can('create', \App\Models\Asset::class)
                    <a href="{{ AssetResource::getUrl('create') }}" style="{{ $heroButton }}; background: #F2B544; color: #262626; border-color: #F2B544">+ Nouveau bien</a>
                @endcan
            </div>
        </div>

        {{-- Valeurs --}}
        <section aria-label="Valeurs" style="margin-top: -44px; padding: 0 28px; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr))">
            @if ($hasAssets)
            <div style="background: #262626; color: #fff; padding: 20px 22px; display: flex; flex-direction: column; gap: 6px">
                <span style="font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: #B9BFC8">Valeur d'acquisition totale</span>
                <span class="pat-mono" data-figure="acquisition" style="font-size: 20px; font-weight: 600">{{ $money($d['values']['acquisition']) }} <span style="font-size: 13px; color: #B9BFC8">FCFA</span></span>
            </div>
            @endif
            @if ($d['values']['net'] !== null)
                <div style="background: #fff; border: 1px solid #D7DBE1; padding: 20px 22px; display: flex; flex-direction: column; gap: 6px">
                    <span style="{{ $label }}">Valeur nette comptable</span>
                    <span class="pat-mono" data-figure="net" style="font-size: 20px; font-weight: 600; color: #0B5D6B">{{ $money($d['values']['net']) }} <span style="font-size: 13px; color: #686868">FCFA</span></span>
                </div>
                <div style="background: #fff; border: 1px solid #D7DBE1; border-left: 0; padding: 20px 22px; display: flex; flex-direction: column; gap: 6px">
                    <span style="{{ $label }}">Dotations de l'exercice {{ $d['year'] }}</span>
                    <span class="pat-mono" data-figure="charges" style="font-size: 20px; font-weight: 600">{{ $money($d['values']['charges']) }} <span style="font-size: 13px; color: #686868">FCFA</span></span>
                </div>
            @endif
        </section>
    </div>

    {{-- Compteurs --}}
    <section aria-label="Indicateurs du patrimoine" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px">
        @if ($hasAssets)
        <a @if ($canAssets) href="{{ AssetResource::getUrl('index') }}" @endif style="{{ $card }}">
            <span style="{{ $label }}">Total des biens</span>
            <span data-figure="total" style="font-size: 28px; font-weight: 800">{{ $money($c['total']) }}</span>
            <span style="font-size: 12px; color: #1E6E43">+{{ $c['new_this_month'] }} ce mois</span>
        </a>
        <div style="{{ $card }}">
            <span style="{{ $label }}">En service</span>
            <span data-figure="in_service" style="font-size: 28px; font-weight: 800">{{ $money($s[AssetStatus::InService->value]) }}</span>
            <span style="font-size: 12px; color: #686868">{{ round($s[AssetStatus::InService->value] * 100 / $total) }} % du parc</span>
        </div>
        <div style="{{ $card }}">
            <span style="{{ $label }}">En stock</span>
            <span data-figure="in_stock" style="font-size: 28px; font-weight: 800">{{ $money($s[AssetStatus::InStock->value]) }}</span>
            <span style="font-size: 12px; color: #686868">Biens en réserve</span>
        </div>
        @endif
        <a @if ($c['open_jobs'] !== null) href="{{ MaintenanceResource::getUrl('index') }}" @endif style="{{ $card }}">
            <span style="{{ $label }}">En maintenance</span>
            <span data-figure="in_maintenance" style="font-size: 28px; font-weight: 800">{{ $hasAssets ? $money($s[AssetStatus::InMaintenance->value]) : $c['open_jobs'] }}</span>
            @if ($c['open_jobs'] !== null && $hasAssets)
                <span style="font-size: 12px; color: #8A5A00">{{ $c['open_jobs'] }} interventions ouvertes</span>
            @endif
        </a>
        @if ($hasAssets)
        <div style="{{ $card }}">
            <span style="{{ $label }}">Hors service</span>
            <span data-figure="out_of_service" style="font-size: 28px; font-weight: 800">{{ $money($s[AssetStatus::OutOfService->value]) }}</span>
            <span style="font-size: 12px; color: #A8261B">À réparer ou à sortir du patrimoine</span>
        </div>
        @endif
        <a @if ($c['overdue_loans'] !== null) href="{{ LoanResource::getUrl('index') }}" @endif style="{{ $card }}">
            <span style="{{ $label }}">Prêtés</span>
            <span data-figure="on_loan" style="font-size: 28px; font-weight: 800">{{ $hasAssets ? $money($s[AssetStatus::OnLoan->value]) : $c['overdue_loans'] }}</span>
            @if ($c['overdue_loans'] !== null && $hasAssets)
                <span style="font-size: 12px; color: #A8261B">{{ $c['overdue_loans'] }} en retard</span>
            @endif
        </a>
    </section>

    @if ($hasAssets)
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px">
        {{-- Biens par département --}}
        <x-filament::section heading="Biens par département">
            @if (auth()->user()?->can('view', \App\Support\Reports\ReportCatalog::find('by_department')))
                <x-slot name="afterHeader"><a href="{{ Reports::getUrl(['rapport' => 'by_department']) }}" style="font-size: 13px; color: #0B5D6B">Rapport détaillé</a></x-slot>
            @endif
            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px">
                @forelse ($d['departments']['items'] as $row)
                    <div style="display: grid; grid-template-columns: 150px 1fr 56px; align-items: center; gap: 10px">
                        <span>{{ $row['name'] }}</span>
                        <div style="height: 10px; background: #E9ECF0"><div style="width: {{ $row['percent'] }}%; height: 10px; background: #0B5D6B"></div></div>
                        <span style="text-align: right; font-weight: 600" data-department="{{ $row['id'] }}">{{ $row['count'] }}</span>
                    </div>
                @empty
                    <span style="color: #686868">Aucun bien dans votre périmètre.</span>
                @endforelse
                @if ($d['departments']['others'])
                    <div style="display: grid; grid-template-columns: 150px 1fr 56px; align-items: center; gap: 10px">
                        <span>Autres ({{ $d['departments']['others']['number'] }})</span>
                        <div style="height: 10px; background: #E9ECF0"><div style="width: 40%; height: 10px; background: #7FA9B1"></div></div>
                        <span style="text-align: right; font-weight: 600">{{ $d['departments']['others']['count'] }}</span>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Statut et site --}}
        <x-filament::section heading="Répartition par statut et par site">
            @php
                $segments = [
                    ['En service', $s[AssetStatus::InService->value], '#0B5D6B'],
                    ['En stock', $s[AssetStatus::InStock->value], '#7FA9B1'],
                    ['Maintenance', $s[AssetStatus::InMaintenance->value], '#D9A23A'],
                    ['Hors service', $s[AssetStatus::OutOfService->value], '#C7564B'],
                    ['Prêtés', $s[AssetStatus::OnLoan->value], '#2F4A9E'],
                    ['Cédés / réformés', $s[AssetStatus::TransferredOut->value] + $s[AssetStatus::Retired->value], '#9AA3AD'],
                ];
            @endphp
            <div style="display: flex; flex-direction: column; gap: 14px">
                <div style="display: flex; height: 18px; overflow: hidden" role="img" aria-label="Répartition des biens par statut">
                    @foreach ($segments as [$name, $count, $color])
                        <div style="width: {{ $pct($count) }}%; background: {{ $color }}"></div>
                    @endforeach
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 8px 16px; font-size: 12px; color: #3A3A3A">
                    @foreach ($segments as [$name, $count, $color])
                        <span style="display: flex; align-items: center; gap: 6px"><span style="width: 10px; height: 10px; border-radius: 2px; background: {{ $color }}"></span>{{ $name }} {{ $money($count) }}</span>
                    @endforeach
                </div>
                <div style="overflow-x: auto">
                    <table style="width: 100%; font-size: 13px; border-collapse: collapse">
                        <thead>
                            <tr style="text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #686868">
                                <th style="padding: 6px 8px">Site</th>
                                <th style="padding: 6px 8px; text-align: right">Biens</th>
                                <th style="padding: 6px 8px; text-align: right">Valeur d'acquisition</th>
                                @if ($d['values']['net'] !== null)<th style="padding: 6px 8px; text-align: right">VNC</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['sites'] as $site)
                                <tr style="border-top: 1px solid #E9ECF0">
                                    <td style="padding: 6px 8px">{{ $site['name'] }}</td>
                                    <td style="padding: 6px 8px; text-align: right">{{ $site['count'] }}</td>
                                    <td class="pat-mono" style="padding: 6px 8px; text-align: right">{{ $money($site['acquisition']) }}</td>
                                    @if ($d['values']['net'] !== null)<td class="pat-mono" style="padding: 6px 8px; text-align: right">{{ $money($site['net']) }}</td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </x-filament::section>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px">
        <x-filament::section heading="Acquisitions récentes">
            <div style="overflow-x: auto">
                <table style="width: 100%; font-size: 13px; border-collapse: collapse">
                    <thead>
                        <tr style="text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #686868"><th style="padding: 6px 8px">N°</th><th style="padding: 6px 8px">Désignation</th><th style="padding: 6px 8px">Date</th><th style="padding: 6px 8px; text-align: right">Montant</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($d['recent_assets'] as $asset)
                            <tr style="border-top: 1px solid #E9ECF0">
                                <td style="padding: 6px 8px"><a class="pat-mono" style="font-weight: 600; color: #0B5D6B" @if ($canAssets) href="{{ AssetResource::getUrl('view', ['record' => $asset]) }}" @endif>{{ $asset->number }}</a></td>
                                <td style="padding: 6px 8px">{{ $asset->label() }}</td>
                                <td style="padding: 6px 8px">{{ $asset->acquired_on->format('d/m/Y') }}</td>
                                <td class="pat-mono" style="padding: 6px 8px; text-align: right">{{ $money($asset->acquisition_cost) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" style="padding: 12px 8px; color: #686868">Aucune acquisition.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($d['recent_transfers'] !== null)
            <x-filament::section heading="Transferts récents">
                <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column">
                    @forelse ($d['recent_transfers'] as $movement)
                        <li style="display: flex; gap: 12px; padding: 10px 0; border-top: 1px solid #E9ECF0; font-size: 14px">
                            <span class="pat-mono" style="font-weight: 600; color: #0B5D6B">{{ $movement->asset->number }}</span>
                            <span style="flex: 1">{{ $movement->asset->label() }}<br><span style="font-size: 12px; color: #686868">{{ $movement->assignmentLabel('from') }} → {{ $movement->assignmentLabel('to') }}</span></span>
                            <span style="font-size: 12px; color: #686868">{{ $movement->moved_on->format('d/m') }}</span>
                        </li>
                    @empty
                        <li style="color: #686868">Aucun transfert.</li>
                    @endforelse
                </ul>
            </x-filament::section>
        @endif
    </div>

    @endif

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px">
        @if ($hasAssets)
        <x-filament::section heading="Biens nécessitant une intervention">
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 14px">
                @forelse ($d['to_handle'] as $asset)
                    <div style="display: flex; align-items: center; gap: 10px">
                        <x-filament::badge :color="$asset->status->getColor()">{{ $asset->status->getLabel() }}</x-filament::badge>
                        <span style="flex: 1">{{ $asset->label() }} · <span class="pat-mono" style="color: #0B5D6B">{{ $asset->number }}</span></span>
                    </div>
                @empty
                    <span style="color: #686868">Rien à traiter.</span>
                @endforelse
            </div>
        </x-filament::section>
        @endif

        @if ($d['last_inventory'])
            @php($inventory = $d['last_inventory']['inventory'])
            @php($ic = $d['last_inventory']['counters'])
            <x-filament::section heading="Dernier inventaire">
                <p style="margin: 0 0 12px; font-size: 14px"><span class="pat-mono" style="font-weight: 600; color: #0B5D6B">{{ $inventory->reference }}</span> · {{ $inventory->scopeLabel() }} · clôturé le {{ $inventory->closed_at?->format('d/m/Y') }}</p>
                <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; text-align: center">
                    @foreach ([[$ic['present'], 'Présents', '#E4F2EA', '#1E6E43'], [$ic['missing'], 'Manquants', '#FBE7E5', '#A8261B'], [$ic['discrepancies'], 'Écarts', '#FBF0D9', '#8A5A00'], [$ic['unregistered'], 'Non enregistrés', '#E6EBF8', '#2F4A9E']] as [$n, $name, $bg, $fg])
                        <div style="background: {{ $bg }}; border-radius: 8px; padding: 10px 4px"><div style="font-size: 20px; font-weight: 800; color: {{ $fg }}">{{ $n }}</div><div style="font-size: 12px; color: {{ $fg }}">{{ $name }}</div></div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>

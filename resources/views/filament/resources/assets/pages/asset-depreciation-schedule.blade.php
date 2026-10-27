@php
    $report = $this->getReport();
    $schedule = $report['schedule'];
    $money = fn (?int $value): string => \App\Support\Money::format($value);
@endphp
<x-filament-panels::page>
    @if ($schedule === null)
        <x-filament::section>
            <p class="text-sm text-gray-600">
                @if ($this->asset()->is_depreciable)
                    Aucun plan d'amortissement : la date de mise en service, la durée ou le prix d'acquisition de ce bien manque.
                @else
                    Ce bien n'est pas amortissable (catégorie, seuil d'immobilisation ou forçage sur la fiche, RG-30) : il est suivi sans plan.
                @endif
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Paramètres</x-slot>
            <x-slot name="afterHeader">
                <div class="flex flex-wrap gap-2">
                    @if ($schedule->isValidated())
                        <x-filament::badge color="success">Plan validé le {{ $schedule->validated_at->format('d/m/Y') }}</x-filament::badge>
                    @else
                        <x-filament::badge color="warning">Plan non validé</x-filament::badge>
                    @endif
                    <x-filament::badge color="gray">Version {{ $schedule->version }}</x-filament::badge>
                </div>
            </x-slot>
            <dl class="grid gap-x-6 gap-y-4" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
                @foreach ($report['parameters'] as $parameter)
                    <div>
                        <dt class="text-xs font-semibold text-gray-600">{{ $parameter['label'] }}</dt>
                        <dd class="text-sm">{{ $parameter['value'] }}</dd>
                    </div>
                @endforeach
                @foreach ($report['counters'] as $label => $value)
                    @if ($loop->last)
                        <div>
                            <dt class="text-xs font-semibold text-gray-600">{{ $label }}</dt>
                            <dd class="text-sm">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section heading="Tableau d'amortissement">
            @include('filament.depreciation.lines-table', ['report' => $report, 'full' => true])
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="Évolution de la VNC">
                <div role="img" aria-label="VNC en fin d'exercice : {{ collect($report['chart'])->map(fn ($point) => $point['label'].' '.$money($point['value']))->implode(', ') }}"
                     class="flex items-end gap-3" style="height: 200px; border-bottom: 1px solid #8F959F; padding: 0 4px">
                    @foreach ($report['chart'] as $point)
                        <div class="flex flex-1 flex-col items-center justify-end gap-1" style="height: 100%">
                            <span class="pat-mono" style="font-size: 11px">{{ $money($point['value']) }}</span>
                            <div style="width: 100%; max-width: 56px; height: {{ max(2, (int) round($point['percent'] * 1.4)) }}px; background: {{ $loop->first ? '#B8D3D8' : '#0B5D6B' }}; border-radius: 4px 4px 0 0"></div>
                        </div>
                    @endforeach
                </div>
                <div class="flex gap-3 text-xs text-gray-600" style="padding: 4px 4px 0">
                    @foreach ($report['chart'] as $point)
                        <span class="flex-1 text-center">{{ $point['label'] }}</span>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section heading="Historique des modifications">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                                <th class="py-2 pe-3">Date</th>
                                <th class="py-2 pe-3">Paramètre</th>
                                <th class="py-2 pe-3">Ancienne</th>
                                <th class="py-2 pe-3">Nouvelle</th>
                                <th class="py-2 pe-3">Par</th>
                                <th class="py-2">Motif</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['history'] as $entry)
                                <tr class="border-t border-gray-200">
                                    <td class="py-2 pe-3 pat-mono">{{ $entry['date'] }}</td>
                                    <td class="py-2 pe-3">{{ $entry['parameter'] }}</td>
                                    <td class="py-2 pe-3">{{ $entry['old'] }}</td>
                                    <td class="py-2 pe-3">{{ $entry['new'] }}</td>
                                    <td class="py-2 pe-3">{{ $entry['by'] }}</td>
                                    <td class="py-2">{{ $entry['reason'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-2 text-gray-600">Aucune modification.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        @if (count($report['versions']) > 1)
            <x-filament::section heading="Versions du plan" description="Chaque validation conserve une version (RG-38).">
                <ul class="space-y-1 text-sm">
                    @foreach ($report['versions'] as $version)
                        <li><span class="font-semibold">{{ $version['version'] }}</span> · {{ $version['state'] }} · {{ $version['validated'] }} · {{ $version['parameters'] }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

@php
    $summary = $this->getSummary();
    $totals = $summary['totals'];
    $money = fn (int $value): string => \App\Support\Money::format($value);
    $counts = [
        \App\Services\DepreciationRegister::TAB_CURRENT => $totals['current'],
        \App\Services\DepreciationRegister::TAB_ENDING => $totals['ending'],
        \App\Services\DepreciationRegister::TAB_FULLY => $totals['fully'],
        \App\Services\DepreciationRegister::TAB_NONE => $totals['none'],
    ];
@endphp
<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-sm font-semibold">
            Exercice comptable
            <select wire:model.live="year" class="rounded-md border border-gray-400 bg-white px-3 py-2 text-sm font-semibold" style="min-height: 44px">
                @foreach ($this->getYearOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @if (\App\Filament\Resources\FiscalYears\FiscalYearResource::canViewAny())
            <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\FiscalYears\FiscalYearResource::getUrl()">Exercices</x-filament::button>
        @endif
    </div>

    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))">
        @foreach ([
            ['Valeur d\'origine', $money($totals['original']), $totals['count'].' biens amortissables'],
            ['Dotations '.$this->year, $money($totals['amount']), null],
            ['Amort. cumulés', $money($totals['accumulated']), null],
            ['VNC totale', $money($totals['net']), null],
            ['Totalement amorties', (string) $totals['fully'], 'dont '.$totals['ending'].' en '.$this->year],
        ] as [$label, $value, $hint])
            <x-filament::section compact>
                <div class="flex flex-col gap-1">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-600">{{ $label }}</span>
                    <span class="pat-mono text-lg font-semibold" @if ($loop->index === 3) style="color: #0B5D6B" @endif>{{ $value }}</span>
                    @if ($hint)
                        <span class="text-xs text-gray-600">{{ $hint }}</span>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::tabs label="Situation">
        @foreach (\App\Services\DepreciationRegister::TABS as $key => $label)
            <x-filament::tabs.item :active="$this->tab === $key" wire:click="selectTab('{{ $key }}')">
                {{ $label }}{{ $key === \App\Services\DepreciationRegister::TAB_ENDING ? ' en '.$this->year : '' }} ({{ $counts[$key] }})
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}

    <x-filament::section heading="Récapitulatif par catégorie">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                        <th class="py-2 pe-4">Catégorie</th>
                        <th class="py-2 pe-4">Durée par défaut</th>
                        <th class="py-2 pe-4 text-right">Biens</th>
                        <th class="py-2 pe-4 text-right">Valeur d'origine</th>
                        <th class="py-2 pe-4 text-right">Dotations {{ $this->year }}</th>
                        <th class="py-2 pe-4 text-right">Cumul</th>
                        <th class="py-2 text-right">VNC</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['categories'] as $category)
                        <tr class="border-t border-gray-200">
                            <td class="py-2 pe-4">{{ $category['name'] }}</td>
                            <td class="py-2 pe-4">{{ $category['duration'] ? $category['duration'].' ans' : '·' }}</td>
                            <td class="py-2 pe-4 text-right pat-mono">{{ $category['count'] }}</td>
                            <td class="py-2 pe-4 text-right pat-mono">{{ $money($category['original']) }}</td>
                            <td class="py-2 pe-4 text-right pat-mono">{{ $money($category['amount']) }}</td>
                            <td class="py-2 pe-4 text-right pat-mono">{{ $money($category['accumulated']) }}</td>
                            <td class="py-2 text-right pat-mono">{{ $money($category['net']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-300 font-bold">
                        <td class="py-2 pe-4">Total</td>
                        <td></td>
                        <td class="py-2 pe-4 text-right pat-mono">{{ $totals['count'] }}</td>
                        <td class="py-2 pe-4 text-right pat-mono">{{ $money($totals['original']) }}</td>
                        <td class="py-2 pe-4 text-right pat-mono">{{ $money($totals['amount']) }}</td>
                        <td class="py-2 pe-4 text-right pat-mono">{{ $money($totals['accumulated']) }}</td>
                        <td class="py-2 text-right pat-mono">{{ $money($totals['net']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

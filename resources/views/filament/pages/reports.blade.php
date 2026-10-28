@php
    use App\Support\Money;

    $definition = $this->definition();
    $options = $this->options();
    $data = $this->preview();
    $select = 'font: inherit; font-size: 14px; border: 1px solid #8F959F; border-radius: 6px; padding: 9px 10px; background: #fff; width: 100%; min-height: 44px; box-sizing: border-box';
    $fieldLabel = 'font-size: 12px; font-weight: 600; color: #3A3A3A';
    $filterFields = [
        'site' => ['site_id', 'Site', 'Tous'],
        'department' => ['department_id', 'Département', 'Tous'],
        'service' => ['service_id', 'Service', 'Tous'],
        'category' => ['category_id', 'Catégorie', 'Toutes'],
        'status' => ['status', 'Statut', 'Tous'],
        'holder' => ['holder_id', 'Responsable', 'Tous'],
        'year' => ['year', 'Exercice', 'Exercice en cours'],
    ];
@endphp
<x-filament-panels::page>
    <div style="display: flex; flex-wrap: wrap; gap: 18px; align-items: flex-start">
        <nav aria-label="Catalogue des rapports" style="flex: 1 1 250px; background: #fff; border: 1px solid #D7DBE1; border-radius: 12px; padding: 6px 8px 10px; display: flex; flex-direction: column">
            @foreach ($this->catalog() as $group => $reports)
                <div style="font-size: 11px; color: #686868; text-transform: uppercase; letter-spacing: .09em; font-weight: 700; padding: 10px 12px 4px">{{ $group }}</div>
                @foreach ($reports as $item)
                    <button type="button" wire:click="selectReport('{{ $item->key }}')" aria-pressed="{{ $item->key === $this->report ? 'true' : 'false' }}"
                        style="display: block; width: 100%; min-height: 38px; padding: 0 12px; border: 0; border-radius: 6px; font: inherit; font-size: 14px; text-align: left; cursor: pointer; color: #2E2E2E; {{ $item->key === $this->report ? 'background: #E3F0F2; font-weight: 600; box-shadow: inset 3px 0 0 #0B5D6B' : 'background: transparent' }}">{{ $item->label }}</button>
                @endforeach
            @endforeach
        </nav>

        <div style="flex: 3 1 560px; min-width: 0; display: flex; flex-direction: column; gap: 14px">
            @if ($definition)
                <section style="background: #fff; border: 1px solid #D7DBE1; border-radius: 12px; padding: 16px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end" aria-label="Filtres">
                    @foreach ($filterFields as $filter => [$field, $text, $all])
                        @if ($definition->supports($filter))
                            <div style="display: flex; flex-direction: column; gap: 5px">
                                <label for="f-{{ $field }}" style="{{ $fieldLabel }}">{{ $text }}</label>
                                <select id="f-{{ $field }}" wire:model.live="filters.{{ $field }}" style="{{ $select }}">
                                    <option value="">{{ $all }}</option>
                                    @foreach ($options[$filter] as $value => $name)
                                        <option value="{{ $value }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    @endforeach
                    @if ($definition->supports('period'))
                        <div style="display: flex; flex-direction: column; gap: 5px">
                            <label for="f-from" style="{{ $fieldLabel }}">Du</label>
                            <input id="f-from" type="date" wire:model.live="filters.from" style="{{ $select }}">
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 5px">
                            <label for="f-to" style="{{ $fieldLabel }}">Au</label>
                            <input id="f-to" type="date" wire:model.live="filters.to" style="{{ $select }}">
                        </div>
                    @endif
                </section>

                @if ($data)
                    <section style="background: #fff; border: 1px solid #D7DBE1; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; gap: 14px">
                        <span style="font-size: 13px; font-weight: 600; color: #686868">Aperçu · {{ $data->count() }} ligne(s)</span>
                        <div style="border: 1px solid #D7DBE1; border-radius: 6px; padding: 24px 24px 16px; background: #fff; display: flex; flex-direction: column; gap: 14px">
                            <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; border-bottom: 2px solid #2E2E2E; padding-bottom: 10px">
                                <div style="display: flex; flex-direction: column; gap: 2px">
                                    <span style="font-size: 12px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase">{{ $data->churchName }} · Gestion du patrimoine</span>
                                    <span style="font-size: 20px; font-weight: 800">{{ $data->title }}</span>
                                    <span style="font-size: 12px; color: #686868">{{ $data->subtitle() }}</span>
                                </div>
                                <span style="font-size: 12px; color: #686868; text-align: right">Édité le {{ $data->editedAt->format('d/m/Y à H:i') }}<br>par {{ $data->editedBy }}</span>
                            </div>
                            <div style="overflow-x: auto">
                                <table style="width: 100%; border-collapse: collapse; font-size: 13px">
                                    <thead>
                                        <tr>
                                            @foreach ($data->headings as $index => $heading)
                                                <th style="text-align: {{ in_array($index, [...$data->moneyColumns, ...$data->numberColumns], true) ? 'right' : 'left' }}; font-size: 11px; color: #686868; text-transform: uppercase; letter-spacing: .06em; padding: 8px 10px; border-bottom: 2px solid #2E2E2E; white-space: nowrap">{{ $heading }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse (array_slice($data->rows, 0, \App\Filament\Pages\Reports::PREVIEW_ROWS) as $row)
                                            <tr>
                                                @foreach ($row as $index => $cell)
                                                    @if (in_array($index, $data->moneyColumns, true))
                                                        <td class="pat-mono" style="padding: 8px 10px; border-bottom: 1px solid #E9ECF0; text-align: right">{{ Money::format($cell === null ? null : (int) $cell) }}</td>
                                                    @elseif (in_array($index, $data->numberColumns, true))
                                                        <td class="pat-mono" style="padding: 8px 10px; border-bottom: 1px solid #E9ECF0; text-align: right">{{ $cell }}</td>
                                                    @else
                                                        <td style="padding: 8px 10px; border-bottom: 1px solid #E9ECF0">{{ $cell ?? '—' }}</td>
                                                    @endif
                                                @endforeach
                                            </tr>
                                        @empty
                                            <tr><td colspan="{{ count($data->headings) }}" style="padding: 24px 10px; text-align: center; color: #686868">Aucune ligne pour ces filtres.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            @if ($data->count() > \App\Filament\Pages\Reports::PREVIEW_ROWS)
                                <span style="font-size: 12px; color: #686868">… {{ $data->count() - \App\Filament\Pages\Reports::PREVIEW_ROWS }} ligne(s) de plus dans l'export.</span>
                            @endif
                            <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; font-size: 13px; font-weight: 600; border-top: 1px solid #2E2E2E; padding-top: 8px">
                                <span data-footer="left">{{ $data->footerLeft }}</span>
                                <span class="pat-mono" data-footer="right">{{ $data->footerRight }}</span>
                            </div>
                        </div>
                    </section>
                @endif
            @endif
        </div>
    </div>
</x-filament-panels::page>

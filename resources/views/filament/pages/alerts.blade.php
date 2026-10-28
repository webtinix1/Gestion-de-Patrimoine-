@php
    $all = $this->alerts();
    $tabs = ['all' => 'Toutes', ...\App\Enums\AlertType::groups()];
    $shown = $this->group === 'all' ? $all : array_values(array_filter($all, fn (array $a): bool => $a['group'] === $this->group));
    $colors = ['danger' => ['#FBE7E5', '#A8261B'], 'warning' => ['#FBF0D9', '#8A5A00'], 'info' => ['#E6EBF8', '#2F4A9E'], 'primary' => ['#E3F0F2', '#0B5D6B'], 'gray' => ['#E9ECF0', '#4A5560']];
@endphp
<x-filament-panels::page>
    <div role="tablist" aria-label="Filtrer les alertes" style="display: flex; flex-wrap: wrap; gap: 8px">
        @foreach ($tabs as $key => $label)
            @php($count = $key === 'all' ? count($all) : count(array_filter($all, fn (array $a): bool => $a['group'] === $key)))
            <button type="button" role="tab" aria-selected="{{ $this->group === $key ? 'true' : 'false' }}" wire:click="selectGroup('{{ $key }}')"
                style="min-height: 40px; padding: 0 14px; border-radius: 999px; font: inherit; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; {{ $this->group === $key ? 'background: #0B5D6B; color: #fff; border: 1px solid #0B5D6B' : 'background: #fff; color: #2E2E2E; border: 1px solid #8F959F' }}">
                {{ $label }} <span style="font-weight: 700">{{ $count }}</span>
            </button>
        @endforeach
    </div>

    <section aria-label="Liste des alertes" style="background: #fff; border: 1px solid #D7DBE1; border-radius: 12px">
        @forelse ($shown as $alert)
            @php([$bg, $fg] = $colors[$alert['type']->getColor()])
            <div data-alert="{{ $alert['id'] }}" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center; padding: 16px 20px; border-bottom: 1px solid #E9ECF0">
                <span aria-hidden="true" title="{{ $alert['unread'] ? 'Non lue' : 'Lue' }}" style="width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: {{ $alert['unread'] ? '#0B5D6B' : 'transparent' }}"></span>
                <div style="flex: 1 1 360px; display: flex; flex-direction: column; gap: 3px; min-width: 0">
                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px">
                        <span style="background: {{ $bg }}; color: {{ $fg }}; padding: 4px 8px; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase">{{ $alert['type']->getLabel() }}</span>
                        <span style="font-size: 15px; font-weight: 600">{{ $alert['title'] }}</span>
                    </div>
                    <span style="font-size: 13px; color: #686868">{{ $alert['detail'] }}</span>
                </div>
                <span style="font-size: 12px; color: #686868; white-space: nowrap">{{ $alert['when'] }}</span>
                @if ($alert['href'])
                    <x-filament::button tag="a" :href="$alert['href']" color="gray">{{ $alert['action'] }}</x-filament::button>
                @endif
                @if ($alert['unread'])
                    <x-filament::button color="gray" wire:click="markRead({{ $alert['id'] }})">Marquer comme lue</x-filament::button>
                @endif
            </div>
        @empty
            <p style="margin: 0; padding: 40px 20px; text-align: center; color: #686868">Aucune alerte dans cette catégorie.</p>
        @endforelse
    </section>
</x-filament-panels::page>

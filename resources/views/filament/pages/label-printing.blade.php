@php
    $assets = $this->selectedAssets();
    $preview = $this->preview();
    $template = $this->template();
@endphp

<x-filament-panels::page>
    <p class="text-sm text-gray-600">
        {{ $assets->count() }} {{ $assets->count() > 1 ? 'biens sélectionnés' : 'bien sélectionné' }} · aperçu avant impression
    </p>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-1">
            <x-filament::section heading="Biens à étiqueter">
                <div class="flex flex-col gap-4">
                    {{ $this->selectionForm }}

                    <a href="{{ \App\Filament\Resources\Assets\AssetResource::getUrl('index') }}" class="text-sm text-primary-600 underline">
                        Sélectionner depuis la liste des biens
                    </a>

                    @if ($assets->isEmpty())
                        <p class="text-sm text-gray-600">Aucun bien sélectionné.</p>
                    @else
                        <ul class="flex flex-col divide-y divide-gray-200 text-sm">
                            @foreach ($assets as $asset)
                                <li wire:key="asset-{{ $asset->id }}" class="flex items-center gap-3 py-2">
                                    <span class="pat-mono text-xs font-semibold text-primary-600">{{ $asset->number }}</span>
                                    <span class="min-w-0 flex-1 truncate">{{ $asset->label() }}</span>
                                    <button
                                        type="button"
                                        wire:click="removeAsset({{ $asset->id }})"
                                        class="rounded border border-gray-300 px-2 py-1 text-gray-600 hover:bg-gray-50"
                                        aria-label="Retirer {{ $asset->number }}"
                                    >✕</button>
                                </li>
                            @endforeach
                        </ul>
                        <button type="button" wire:click="clearAssets" class="self-start text-sm text-gray-600 underline">Vider la sélection</button>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section heading="Options d'impression">
                {{ $this->form }}

                @if ($template === null)
                    <p class="mt-3 text-sm text-danger-600">Aucun modèle pour ce support : créez-en un dans « Modèles d'étiquettes ».</p>
                @endif
            </x-filament::section>
        </div>

        <x-filament::section heading="Aperçu" class="xl:col-span-2">
            @if ($this->tooBig())
                <p class="text-sm text-danger-600">Ce modèle ne tient pas sur son support : corrigez ses dimensions dans « Modèles d'étiquettes ».</p>
            @elseif ($preview === null)
                <p class="text-sm text-gray-600">Choisissez au moins un bien pour voir l'aperçu.</p>
            @else
                <div class="flex flex-col gap-3">
                    <p class="text-sm text-gray-600">{{ $preview['info'] }}</p>

                    <div class="overflow-x-auto rounded-xl bg-gray-200 p-4">
                        <div style="zoom: {{ $template->sheet_layout->isSheet() ? 0.62 : min(1.6, 300 / ($preview['width'] * 3.7795)) }}; display: flex; flex-direction: column; align-items: center; gap: 12px;">
                            @include('labels.pages', ['pages' => $preview['pages'], 'mode' => 'screen', 'pageWidth' => $preview['width'], 'pageHeight' => $preview['height']])
                        </div>
                    </div>

                    @if ($preview['shown'] < $preview['total'])
                        <p class="text-sm text-gray-600">
                            L'aperçu montre les {{ $preview['shown'] }} premières étiquettes sur {{ $preview['total'] }}. Le PDF contient toutes les étiquettes.
                        </p>
                    @endif
                </div>
            @endif
        </x-filament::section>
    </div>

    @include('labels.styles')
</x-filament-panels::page>

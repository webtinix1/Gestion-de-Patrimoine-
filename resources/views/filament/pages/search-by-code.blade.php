<x-filament-panels::page>
    <x-filament::section heading="Scanner ou saisir un numéro">
        <form wire:submit="search" class="flex flex-col gap-4">
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" size="lg">Rechercher</x-filament::button>
                <span class="text-sm text-gray-600">
                    Une douchette USB se comporte comme un clavier suivi de la touche Entrée : la fiche s'ouvre dès la lecture.
                </span>
            </div>
        </form>
    </x-filament::section>

    @if ($unknownCode)
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge color="danger">Inconnu</x-filament::badge>
                <span class="font-semibold">Aucun bien avec ce code</span>
                <span class="pat-mono">{{ $unknownCode }}</span>
                @if ($this->canCreateAsset())
                    <x-filament::button tag="a" :href="\App\Filament\Resources\Assets\AssetResource::getUrl('create')" color="gray">
                        Créer la fiche de ce bien
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Derniers codes lus sur ce poste">
        @php($history = $this->getHistory())

        @if ($history === [])
            <p class="text-sm text-gray-600">Aucun code lu pour l'instant.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                            <th class="py-2 pe-4">Heure</th>
                            <th class="py-2 pe-4">Code lu</th>
                            <th class="py-2 pe-4">Résultat</th>
                            <th class="py-2">Bien</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $entry)
                            <tr class="border-t border-gray-200">
                                <td class="py-2 pe-4">{{ $entry['time'] }}</td>
                                <td class="py-2 pe-4 pat-mono">{{ $entry['code'] }}</td>
                                <td class="py-2 pe-4">
                                    @if (! $entry['found'])
                                        <x-filament::badge color="danger" class="w-fit">Inconnu</x-filament::badge>
                                    @elseif ($entry['disposed'])
                                        <x-filament::badge color="gray" class="w-fit">Sorti du patrimoine</x-filament::badge>
                                    @else
                                        <x-filament::badge color="success" class="w-fit">Trouvé</x-filament::badge>
                                    @endif
                                </td>
                                <td class="py-2">{{ $entry['label'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

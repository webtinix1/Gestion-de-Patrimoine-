<x-filament-panels::page>
    <x-filament::section heading="Statuts des biens" description="Liste fixe. Les statuts posés par une opération ne peuvent pas être choisis à la main : ils changent quand le prêt, la maintenance ou la sortie est enregistré.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                        <th class="py-2 pe-4">Statut</th>
                        <th class="py-2 pe-4">Positionné par</th>
                        <th class="py-2 pe-4">Choix manuel</th>
                        <th class="py-2">Compté dans le patrimoine actif</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($statuses as $status)
                        <tr class="border-t border-gray-200">
                            <td class="py-2 pe-4">
                                <x-filament::badge :color="$status->getColor()" class="w-fit">{{ $status->getLabel() }}</x-filament::badge>
                            </td>
                            <td class="py-2 pe-4">{{ $status->setBy() }}</td>
                            <td class="py-2 pe-4">{{ $status->isManuallySelectable() ? 'Oui' : 'Non' }}</td>
                            <td class="py-2">{{ $status->isInActivePatrimony() ? 'Oui' : 'Non' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div class="grid gap-6 md:grid-cols-2">
        <x-filament::section heading="Motifs de sortie">
            <div class="flex flex-wrap gap-2">
                @foreach ($disposalReasons as $reason)
                    <x-filament::badge color="gray">{{ $reason->getLabel() }}</x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Modes de financement">
            <div class="flex flex-wrap gap-2">
                @foreach ($fundingSources as $source)
                    <x-filament::badge color="gray">{{ $source->getLabel() }}</x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

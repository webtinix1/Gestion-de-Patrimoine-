@php
    /** @var array<string, mixed> $report */
    $money = fn (?int $value): string => \App\Support\Money::format($value);
    $full = $full ?? true;
@endphp
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-gray-600">
                <th class="py-2 pe-4">Exercice</th>
                @if ($full)
                    <th class="py-2 pe-4">{{ $report['unit'] }}</th>
                    <th class="py-2 pe-4 text-right">Valeur d'origine</th>
                @endif
                <th class="py-2 pe-4 text-right">Base</th>
                <th class="py-2 pe-4 text-right">Taux</th>
                <th class="py-2 pe-4 text-right">Dotation</th>
                <th class="py-2 pe-4 text-right">Cumul</th>
                <th class="py-2 pe-4 text-right">VNC fin d'exercice</th>
                <th class="py-2">Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['lines'] as $line)
                <tr class="border-t border-gray-200" @if ($line['year'] === $report['currentYear']) style="background: #F0F7F8" @endif>
                    <td class="py-2 pe-4 font-semibold">{{ $line['year'] }}</td>
                    @if ($full)
                        <td class="py-2 pe-4 pat-mono">{{ $line['units'] }}</td>
                        <td class="py-2 pe-4 text-right pat-mono">{{ $money($line['original_value']) }}</td>
                    @endif
                    <td class="py-2 pe-4 text-right pat-mono">{{ $money($line['base']) }}</td>
                    <td class="py-2 pe-4 text-right pat-mono">{{ $line['rate'] }}</td>
                    <td class="py-2 pe-4 text-right pat-mono">{{ $money($line['amount']) }}</td>
                    <td class="py-2 pe-4 text-right pat-mono">{{ $money($line['accumulated']) }}</td>
                    <td class="py-2 pe-4 text-right pat-mono font-semibold">{{ $money($line['net_book_value']) }}</td>
                    <td class="py-2">
                        <x-filament::badge :color="$line['status_color']" class="w-fit">{{ $line['status'] }}</x-filament::badge>
                    </td>
                </tr>
            @endforeach
            @if ($full)
                <tr class="border-t-2 border-gray-300 font-bold">
                    <td class="py-2 pe-4">Total</td>
                    <td class="py-2 pe-4 pat-mono">{{ $report['totals']['units'] }}</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="py-2 pe-4 text-right pat-mono">{{ $money($report['totals']['amount']) }}</td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

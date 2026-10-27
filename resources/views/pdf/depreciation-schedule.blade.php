@php
    $money = fn (?int $value): string => \App\Support\Money::format($value);
    $schedule = $report['schedule'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Plan d'amortissement {{ $asset->number }}</title>
    <style>
        @page { margin: 32px 32px 40px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1f2933; }
        h1 { font-size: 15px; margin: 0 0 2px 0; color: #0B5D6B; }
        h2 { font-size: 10px; margin: 14px 0 6px 0; text-transform: uppercase; letter-spacing: 1px; }
        .church { font-size: 10px; font-weight: bold; margin: 0; }
        .meta { margin: 4px 0 10px 0; color: #52606d; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #0B5D6B; color: #ffffff; text-align: left; padding: 4px; font-size: 8px; }
        td { padding: 4px; border-bottom: 1px solid #d9dee3; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .params td { border: none; padding: 2px 8px 2px 0; }
        .label { color: #52606d; }
        tr.total td { font-weight: bold; border-top: 2px solid #0B5D6B; border-bottom: none; }
        .signature { margin-top: 28px; }
        .signature td { border: none; width: 50%; padding-top: 40px; }
    </style>
</head>
<body>
    <p class="church">{{ $churchName }} ({{ $churchAcronym }})</p>
    <h1>Plan d'amortissement · {{ $asset->number }}</h1>
    <p class="meta">
        {{ $asset->label() }} · {{ $asset->category?->name }} · {{ $asset->site?->name }} › {{ $asset->department?->name }}<br>
        Version {{ $schedule->version }} · {{ $schedule->isValidated() ? 'validée le '.$schedule->validated_at->format('d/m/Y') : 'non validée' }}
        · Édité le {{ $editedAt->format('d/m/Y à H:i') }} · Montants en FCFA
    </p>

    <h2>Paramètres</h2>
    <table class="params">
        @foreach (array_chunk($report['parameters'], 3) as $row)
            <tr>
                @foreach ($row as $parameter)
                    <td><span class="label">{{ $parameter['label'] }} :</span> {{ $parameter['value'] }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>Tableau d'amortissement</h2>
    <table>
        <thead>
            <tr>
                <th>Exercice</th>
                <th>{{ $report['unit'] }}</th>
                <th class="num">Base</th>
                <th class="num">Taux</th>
                <th class="num">Dotation</th>
                <th class="num">Cumul</th>
                <th class="num">VNC fin d'exercice</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['lines'] as $line)
                <tr>
                    <td>{{ $line['year'] }}</td>
                    <td>{{ $line['units'] }}</td>
                    <td class="num">{{ $money($line['base']) }}</td>
                    <td class="num">{{ $line['rate'] }}</td>
                    <td class="num">{{ $money($line['amount']) }}</td>
                    <td class="num">{{ $money($line['accumulated']) }}</td>
                    <td class="num">{{ $money($line['net_book_value']) }}</td>
                    <td>{{ $line['status'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total</td>
                <td>{{ $report['totals']['units'] }}</td>
                <td></td>
                <td></td>
                <td class="num">{{ $money($report['totals']['amount']) }}</td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>

    @if ($report['history'] !== [])
        <h2>Historique des modifications</h2>
        <table>
            <thead>
                <tr><th>Date</th><th>Paramètre</th><th>Ancienne</th><th>Nouvelle</th><th>Par</th><th>Motif</th></tr>
            </thead>
            <tbody>
                @foreach ($report['history'] as $entry)
                    <tr>
                        <td>{{ $entry['date'] }}</td>
                        <td>{{ $entry['parameter'] }}</td>
                        <td>{{ $entry['old'] }}</td>
                        <td>{{ $entry['new'] }}</td>
                        <td>{{ $entry['by'] }}</td>
                        <td>{{ $entry['reason'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="signature">
        <tr>
            <td>Le gestionnaire du patrimoine</td>
            <td>Le comptable</td>
        </tr>
    </table>
</body>
</html>

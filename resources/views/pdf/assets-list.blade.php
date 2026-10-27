<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Liste des biens</title>
    <style>
        @page { margin: 28px 28px 36px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #1f2933; }
        h1 { font-size: 14px; margin: 0 0 2px 0; color: #0B5D6B; }
        .church { font-size: 10px; font-weight: bold; margin: 0; }
        .meta { margin: 6px 0 10px 0; color: #52606d; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #0B5D6B; color: #ffffff; text-align: left; padding: 4px 3px; font-size: 7.5px; }
        td { padding: 3px; border-bottom: 1px solid #d9dee3; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .code { white-space: nowrap; font-weight: bold; }
        tr.total td { font-weight: bold; border-top: 2px solid #0B5D6B; border-bottom: none; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <p class="church">{{ $data->churchName }} ({{ $data->churchAcronym }})</p>
    <h1>Liste des biens</h1>
    <p class="meta">
        Édité le {{ $data->editedAt->format('d/m/Y à H:i') }}
        · {{ $data->count }} {{ $data->count > 1 ? 'biens' : 'bien' }}
        · Valeur totale : {{ \App\Support\Money::format($data->total, true) }}
        <br>
        Filtres : {{ $data->filters === [] ? 'aucun' : implode(' ; ', $data->filters) }}
    </p>

    <table>
        <thead>
            <tr>
                @foreach ($data->headings as $heading)
                    <th @class(['num' => $loop->last])>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($data->rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        @if ($loop->last)
                            <td class="num">{{ \App\Support\Money::format($cell) }}</td>
                        @elseif ($loop->first)
                            <td class="code">{{ $cell }}</td>
                        @else
                            <td>{{ $cell ?? '—' }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="{{ count($data->headings) - 1 }}">Total ({{ $data->count }} {{ $data->count > 1 ? 'biens' : 'bien' }})</td>
                <td class="num">{{ \App\Support\Money::format($data->total) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>

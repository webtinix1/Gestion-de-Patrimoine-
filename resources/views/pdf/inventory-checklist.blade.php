<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Liste de contrôle {{ $inventory->reference }}</title>
    <style>
        @page { margin: 28px 28px 36px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #1f2933; }
        h1 { font-size: 14px; margin: 0 0 2px 0; color: #0B5D6B; }
        .meta { margin: 4px 0 10px 0; color: #52606d; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #0B5D6B; color: #ffffff; text-align: left; padding: 4px; font-size: 7.5px; }
        td { padding: 4px; border-bottom: 1px solid #d9dee3; vertical-align: top; }
        .box { display: inline-block; width: 9px; height: 9px; border: 1px solid #1f2933; margin-right: 3px; }
    </style>
</head>
<body>
    <p style="margin:0;font-weight:bold">{{ $churchName }}</p>
    <h1>Liste de contrôle · {{ $inventory->reference }}</h1>
    <p class="meta">{{ $inventory->scopeLabel() }} · du {{ $inventory->periodLabel() }} · {{ $lines->count() }} biens attendus · édité le {{ $editedAt->format('d/m/Y à H:i') }}</p>
    <table>
        <thead>
            <tr><th>N°</th><th>Désignation</th><th>Local enregistré</th><th>État enregistré</th><th>Pointage</th><th>État constaté / observation</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->asset->number ?? '—' }}</td>
                    <td>{{ $line->asset?->label() }}</td>
                    <td>{{ $line->expectedRoom->name ?? '—' }}</td>
                    <td>{{ $line->expectedCondition->label ?? '—' }}</td>
                    <td>
                        @if ($line->result === \App\Enums\InventoryLineResult::Justified)
                            Justifié ({{ $line->expected_status?->getLabel() }})
                        @else
                            <span class="box"></span>Présent <span class="box"></span>Manquant <span class="box"></span>Ailleurs
                        @endif
                    </td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>

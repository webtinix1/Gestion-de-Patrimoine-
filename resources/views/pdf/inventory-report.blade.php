@php
    $place = fn ($department, $room): string => trim(($department?->name ?? '—').($room ? ' · '.$room->name : ''));
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Procès-verbal d'inventaire {{ $inventory->reference }}</title>
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
        .counters td { border: 1px solid #d9dee3; text-align: center; padding: 6px 2px; }
        .counters strong { display: block; font-size: 13px; }
        .muted { color: #52606d; }
        .signature { margin-top: 28px; page-break-inside: avoid; }
        .signature td { border: none; width: 33%; padding-top: 8px; vertical-align: top; }
        .signature .box { height: 60px; border-bottom: 1px solid #1f2933; margin-top: 6px; }
    </style>
</head>
<body>
    <p class="church">{{ $churchName }} ({{ $churchAcronym }})</p>
    <h1>Procès-verbal d'inventaire · {{ $inventory->reference }}</h1>
    <p class="meta">
        {{ $inventory->scopeLabel() }} · du {{ $inventory->periodLabel() }} · responsable {{ $inventory->manager->fullName() }}<br>
        Statut : {{ $inventory->status->getLabel() }}
        @if ($inventory->closed_at)
            · clôturé le {{ $inventory->closed_at->format('d/m/Y à H:i') }} par {{ $inventory->closer?->fullName() ?? '—' }}
        @endif
        · Édité le {{ $editedAt->format('d/m/Y à H:i') }}
    </p>

    @if ($inventory->participants->isNotEmpty())
        <p class="meta">Participants : {{ $inventory->participants->map(fn ($p) => $p->user->fullName().($p->room ? ' ('.$p->room->name.')' : ''))->join(', ') }}</p>
    @endif

    <table class="counters">
        <tr>
            <td><strong>{{ $counters['expected'] }}</strong>Biens attendus</td>
            <td><strong>{{ $counters['present'] }}</strong>Présents</td>
            <td><strong>{{ $counters['missing'] }}</strong>Manquants</td>
            <td><strong>{{ $counters['other_assignment'] }}</strong>Autre affectation</td>
            <td><strong>{{ $counters['condition_mismatch'] }}</strong>État différent</td>
            <td><strong>{{ $counters['unregistered'] }}</strong>Non enregistrés</td>
            <td><strong>{{ $counters['out_of_scope'] }}</strong>Hors périmètre</td>
            <td><strong>{{ $counters['justified'] }}</strong>Prêtés ou en maintenance</td>
            <td><strong>{{ $counters['pending'] }}</strong>Non vérifiés</td>
        </tr>
    </table>

    @foreach ($sections as $key => $section)
        @if ($section['lines']->isNotEmpty())
            <h2>{{ $section['label'] }} ({{ $section['lines']->count() }})</h2>
            <table>
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Désignation</th>
                        <th>Enregistré</th>
                        <th>Constaté</th>
                        <th>Pointé par</th>
                        <th>Régularisation</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['lines'] as $line)
                        <tr>
                            <td>{{ $line->asset->number ?? '—' }}</td>
                            <td>{{ $line->asset?->label() ?? $line->description }}</td>
                            <td>
                                @if ($line->asset_id)
                                    {{ $place($line->expectedDepartment, $line->expectedRoom) }}
                                    @if ($key === 'condition_mismatch') <br><span class="muted">{{ $line->expectedCondition?->label }}</span> @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($key === 'condition_mismatch')
                                    {{ $line->foundCondition?->label }}
                                @elseif ($key === 'missing' || $key === 'justified')
                                    {{ $key === 'justified' ? ($line->expected_status?->getLabel() ?? '—') : '—' }}
                                @else
                                    {{ $line->foundDepartment || $line->foundRoom ? $place($line->foundDepartment ?? $line->expectedDepartment, $line->foundRoom) : '—' }}
                                    @if ($line->foundCondition) <br><span class="muted">{{ $line->foundCondition->label }}</span> @endif
                                @endif
                            </td>
                            <td>{{ $line->checker?->fullName() ?? 'Application' }}</td>
                            <td>{{ $line->resolution?->getLabel() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @if ($observations->isNotEmpty())
        <h2>Observations</h2>
        <table>
            @foreach ($observations as $line)
                <tr>
                    <td style="width: 25%">{{ $line->checker?->fullName() ?? '—' }}{{ $line->asset ? ' · '.$line->asset->number : '' }}</td>
                    <td>{{ $line->notes }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($inventory->closing_comment)
        <h2>Commentaire de validation</h2>
        <p>{{ $inventory->closing_comment }}</p>
    @endif

    <table class="signature">
        <tr>
            <td>Le responsable de l'inventaire<br><span class="muted">{{ $inventory->manager->fullName() }}</span><div class="box"></div></td>
            <td>Le gestionnaire du patrimoine<div class="box"></div></td>
            <td>Le représentant de l'administration<div class="box"></div></td>
        </tr>
    </table>
</body>
</html>

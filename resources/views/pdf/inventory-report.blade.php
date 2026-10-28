@php
    $place = fn ($department, $room): string => trim(($department?->name ?? '—').($room ? ' · '.$room->name : ''));
@endphp
@extends('pdf.layouts.document')
@section('doc-title')Procès-verbal d'inventaire · {{ $inventory->reference }}@endsection
@section('footer-label')PV {{ $inventory->reference }}@endsection
@section('meta'){{ $inventory->reference }}<br>Édité le {{ $editedAt->format('d/m/Y à H:i') }}@endsection
@section('styles')
        .counters td { border: 1px solid #D7DBE1; text-align: center; padding: 6px 2px; font-size: 7.5px; }
        .counters strong { display: block; font-size: 13px; }
@endsection
@section('content')
    <div class="band">{{ $inventory->scopeLabel() }} · du {{ $inventory->periodLabel() }}</div>

    <table class="kv">
        <tr>
            <td><span>Responsable</span>{{ $inventory->manager->fullName() }}</td>
            <td><span>Statut</span>{{ $inventory->status->getLabel() }}@if ($inventory->closed_at) · clôturé le {{ $inventory->closed_at->format('d/m/Y à H:i') }} par {{ $inventory->closer?->fullName() ?? '—' }}@endif</td>
            <td><span>Biens attendus</span>{{ $counters['expected'] }}</td>
        </tr>
        @if ($inventory->participants->isNotEmpty())
            <tr>
                <td colspan="3"><span>Participants</span>{{ $inventory->participants->map(fn ($p) => $p->user->fullName().($p->room ? ' ('.$p->room->name.')' : ''))->join(', ') }}</td>
            </tr>
        @endif
    </table>

    @if ($perimeterNote ?? null)
        <p style="font-weight:bold;color:#8A5A00">{{ $perimeterNote }}. Les biens hors de ce périmètre n'y figurent pas.</p>
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
            <table class="grid">
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
                            <td class="code">{{ $line->asset->number ?? '—' }}</td>
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
        <table class="grid">
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

    @include('pdf.partials.signatures', ['signatures' => [
        ['Le responsable de l\'inventaire', $inventory->manager->fullName()],
        'Le gestionnaire du patrimoine',
        'Le représentant de l\'administration',
    ]])
@endsection

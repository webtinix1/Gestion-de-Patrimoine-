@php
    $money = fn (?int $value): string => \App\Support\Money::format($value);
    $schedule = $report['schedule'];
@endphp
@extends('pdf.layouts.document')
@section('doc-title')Plan d'amortissement@endsection
@section('footer-label')Plan d'amortissement {{ $asset->number }}@endsection
@section('meta')Version {{ $schedule->version }}<br>Édité le {{ $editedAt->format('d/m/Y à H:i') }}@endsection
@section('content')
    <div class="band">{{ $asset->number }} · {{ $asset->label() }}</div>

    <p class="muted">
        {{ $asset->category?->name }} · {{ $asset->site?->name }} › {{ $asset->department?->name }}
        · {{ $schedule->isValidated() ? 'validé le '.$schedule->validated_at->format('d/m/Y') : 'non validé' }} · montants en FCFA
    </p>

    <table class="kv">
        @foreach (array_chunk($report['parameters'], 3) as $row)
            <tr>
                @foreach ($row as $parameter)
                    <td><span>{{ $parameter['label'] }}</span>{{ $parameter['value'] }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <h2>Tableau d'amortissement</h2>
    <table class="grid">
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
        <table class="grid">
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

    @include('pdf.partials.signatures', ['signatures' => ['Le gestionnaire du patrimoine', 'Le responsable comptable']])
@endsection

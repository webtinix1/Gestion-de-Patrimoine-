@php
    $churchName = $churchName ?? '';
    $inbound = $loan->direction === \App\Enums\LoanDirection::In;
    $blank = '....../....../..........';
@endphp
@extends('pdf.layouts.document')
@section('doc-title'){{ $inbound ? 'Fiche d\'emprunt' : 'Fiche de prêt' }}@endsection
@section('footer-label'){{ $loan->reference }}@endsection
@section('meta'){{ $loan->reference }}<br>Édité le {{ $editedAt->format('d/m/Y') }}<br>par {{ $editedBy }}@endsection
@section('content')
    <div class="band">{{ $inbound ? 'Matériel emprunté' : 'Matériel prêté' }}</div>

    <table class="grid">
        <thead>
            <tr><th>N° patrimonial</th><th>Désignation</th><th>État au départ</th></tr>
        </thead>
        <tbody>
            @foreach ($loan->items as $item)
                <tr>
                    <td class="code">{{ $item->asset->number ?? $item->external_reference ?? '—' }}</td>
                    <td>{{ $item->asset?->label() ?? $item->external_designation }}</td>
                    <td>{{ $item->conditionOut->label ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="kv" style="margin-top: 10px">
        <tr>
            <td><span>{{ $inbound ? 'Propriétaire' : 'Bénéficiaire' }}</span>{{ $loan->borrower_name }}</td>
            <td><span>Contact</span>{{ $loan->borrower_contact ?: '—' }}</td>
            <td><span>Responsable du {{ $inbound ? 'emprunt' : 'prêt' }}</span>{{ $loan->responsible?->fullName() ?? '—' }}</td>
        </tr>
        <tr>
            <td><span>Date de sortie</span>{{ $loan->out_on->format('d/m/Y') }}</td>
            <td><span>Retour prévu</span>{{ $loan->due_on->format('d/m/Y') }}</td>
            <td><span>Retour effectif</span>{{ $loan->returned_on?->format('d/m/Y') ?? $blank }}</td>
        </tr>
    </table>

    <h2>Conditions</h2>
    <p>&#9654; {!! htmlspecialchars($inbound ? 'L\'Église s\'engage à restituer le matériel complet et dans l\'état constaté au départ.' : 'Le bénéficiaire s\'engage à restituer le matériel complet et dans l\'état constaté au départ.', ENT_NOQUOTES) !!}</p>
    <p>&#9654; Toute détérioration ou perte est signalée sans délai au gestionnaire du patrimoine.</p>
    <p>&#9654; {!! htmlspecialchars('L\'état au retour est constaté en présence des deux parties.', ENT_NOQUOTES) !!}</p>

    <div class="band dark">Constat au retour</div>
    @if ($loan->isOngoing())
        <p>État au retour : ........................................................................................</p>
        <p>Observations : ............................................................................................</p>
    @else
        <table class="grid">
            <thead><tr><th>Bien</th><th>État au retour</th><th>Observations</th></tr></thead>
            <tbody>
                @foreach ($loan->items as $item)
                    <tr>
                        <td>{{ $item->asset->number ?? $item->external_reference ?? '—' }}</td>
                        <td>{{ $item->conditionBack->label ?? '—' }}</td>
                        <td>{{ $item->return_notes ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('pdf.partials.signatures', ['signatures' => [$inbound ? 'Pour l\'Église' : 'Pour l\'Église', $inbound ? 'Le propriétaire' : 'Le bénéficiaire']])
@endsection

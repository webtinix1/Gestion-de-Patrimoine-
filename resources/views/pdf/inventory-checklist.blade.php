@php($compact = true)
@extends('pdf.layouts.document')
@section('doc-title')Liste de contrôle · {{ $inventory->reference }}@endsection
@section('footer-label')Liste de contrôle {{ $inventory->reference }}@endsection
@section('meta')Édité le {{ $editedAt->format('d/m/Y à H:i') }}<br>{{ $lines->count() }} biens attendus@endsection
@section('styles')
        .box-check { display: inline-block; width: 9px; height: 9px; border: 1px solid #2E2E2E; margin-right: 3px; }
@endsection
@section('content')
    <div class="band">{{ $inventory->scopeLabel() }} · du {{ $inventory->periodLabel() }}</div>
    <table class="grid">
        <thead>
            <tr><th>N°</th><th>Désignation</th><th>Local enregistré</th><th>État enregistré</th><th>Pointage</th><th>État constaté / observation</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td class="code">{{ $line->asset->number ?? '—' }}</td>
                    <td>{{ $line->asset?->label() }}</td>
                    <td>{{ $line->expectedRoom->name ?? '—' }}</td>
                    <td>{{ $line->expectedCondition->label ?? '—' }}</td>
                    <td>
                        @if ($line->result === \App\Enums\InventoryLineResult::Justified)
                            Justifié ({{ $line->expected_status?->getLabel() }})
                        @else
                            <span class="box-check"></span>Présent <span class="box-check"></span>Manquant <span class="box-check"></span>Ailleurs
                        @endif
                    </td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

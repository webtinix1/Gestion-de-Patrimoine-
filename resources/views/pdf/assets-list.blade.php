@php
    $churchName = $data->churchName;
    $churchAcronym = $data->churchAcronym;
    $editedAt = $data->editedAt;
    $compact = true;
@endphp
@extends('pdf.layouts.document')
@section('doc-title')Liste des biens@endsection
@section('footer-label')Liste des biens@endsection
@section('meta')Édité le {{ $data->editedAt->format('d/m/Y à H:i') }}<br>{{ $data->count }} {{ $data->count > 1 ? 'biens' : 'bien' }}@endsection
@section('content')
    <p class="muted">
        Valeur totale : {{ \App\Support\Money::format($data->total, true) }}
        · Filtres : {{ $data->filters === [] ? 'aucun' : implode(' ; ', $data->filters) }}
    </p>

    <table class="grid">
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
@endsection

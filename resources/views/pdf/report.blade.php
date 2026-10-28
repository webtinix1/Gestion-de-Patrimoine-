@php
    $churchName = $data->churchName;
    $churchAcronym = $data->churchAcronym;
    $editedAt = $data->editedAt;
    $compact = true;
    $isMoney = fn (int $index): bool => in_array($index, $data->moneyColumns, true);
    $isNumber = fn (int $index): bool => $isMoney($index) || in_array($index, $data->numberColumns, true);
@endphp
@extends('pdf.layouts.document')
@section('doc-title'){{ $data->title }}@endsection
@section('footer-label'){{ $data->title }}@endsection
@section('meta')Édité le {{ $data->editedAt->format('d/m/Y à H:i') }}<br>par {{ $data->editedBy }}@endsection
@section('content')
    <p class="muted">{{ $data->subtitle() }}</p>

    <table class="grid">
        <thead>
            <tr>
                @foreach ($data->headings as $index => $heading)
                    <th @class(['num' => $isNumber($index)])>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($data->rows as $row)
                <tr>
                    @foreach ($row as $index => $cell)
                        @if ($isMoney($index))
                            <td class="num">{{ \App\Support\Money::format($cell === null ? null : (int) $cell) }}</td>
                        @elseif ($isNumber($index))
                            <td class="num">{{ $cell }}</td>
                        @else
                            <td @class(['code' => $index === 0])>{{ $cell ?? '—' }}</td>
                        @endif
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($data->headings) }}" class="muted">Aucune ligne pour ces filtres.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="{{ max(1, count($data->headings) - 1) }}">{{ $data->footerLeft }}</td>
                <td class="num">{{ $data->footerRight }}</td>
            </tr>
        </tbody>
    </table>
@endsection

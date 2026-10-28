@extends('pdf.layouts.document')
@section('doc-title'){{ $title }}@endsection
@section('footer-label')Guide utilisateur@endsection
@section('meta')Édité le {{ $editedAt->format('d/m/Y') }}@endsection
@section('styles')
    .guide h1, .guide h2 { text-transform: none; }
    .guide h2 { font-size: 13px; color: #0B5D6B; margin: 18px 0 6px 0; border-bottom: 1px solid #D7DBE1; padding-bottom: 3px; letter-spacing: 0; page-break-after: avoid; }
    .guide h3 { font-size: 10.5px; margin: 12px 0 4px 0; page-break-after: avoid; }
    .guide p, .guide li { font-size: 9.5px; line-height: 1.5; }
    .guide ul, .guide ol { margin: 4px 0 6px 0; padding-left: 18px; }
    .guide table { margin: 6px 0 10px 0; }
    .guide th { text-align: left; background: #F4F6F8; border-bottom: 2px solid #2E2E2E; padding: 4px; font-size: 8.5px; }
    .guide td { border-bottom: 1px solid #D7DBE1; padding: 4px; font-size: 8.5px; vertical-align: top; }
    .guide code { font-size: 8.5px; background: #F4F6F8; }
    .guide blockquote { margin: 6px 0; padding: 4px 10px; border-left: 3px solid #F2B544; background: #FBF0D9; }
@endsection
@section('content')
    <div class="guide">{!! $body !!}</div>
@endsection

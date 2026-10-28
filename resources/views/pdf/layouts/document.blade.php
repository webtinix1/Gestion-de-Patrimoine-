@php
    // E45 : gabarit commun des documents imprimés (en-tête de l'Église, pied de page, signatures).
    // Hors ligne : aucune ressource distante, police DejaVu Sans incluse dans dompdf.
    $initial = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', (string) ($churchAcronym ?? '')) ?: 'É', 0, 1));
    $compact = $compact ?? false;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>@yield('doc-title', 'Document')</title>
    <style>
        @page { margin: 36px 32px 56px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $compact ? '8' : '9' }}px; color: #2E2E2E; }
        h1.doc-title { font-size: {{ $compact ? '15' : '19' }}px; font-weight: bold; text-transform: uppercase; margin: 12px 0 8px 0; line-height: 1.1; }
        h2 { font-size: 9px; margin: 14px 0 6px 0; text-transform: uppercase; letter-spacing: 1px; }
        table { width: 100%; border-collapse: collapse; }
        table.head { border-bottom: 1px solid #2E2E2E; margin-bottom: 4px; }
        table.head td { padding: 0 0 8px 0; vertical-align: top; border: none; }
        .logo { width: 30px; height: 30px; background: #F2B544; color: #262626; text-align: center; font-weight: bold; font-size: 15px; line-height: 30px; }
        .church { font-weight: bold; font-size: 10px; text-transform: uppercase; }
        .sub { color: #686868; font-size: 8px; }
        .right { text-align: right; color: #686868; font-size: 8px; }
        .band { background: #0B5D6B; color: #ffffff; padding: 6px 10px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin: 6px 0 10px 0; }
        .band.dark { background: #2E2E2E; }
        .kv td { width: 33%; padding: 3px 8px 5px 0; vertical-align: top; border: none; font-size: 9px; }
        .kv span { display: block; font-size: 7.5px; font-weight: bold; text-transform: uppercase; color: #686868; letter-spacing: 0.5px; }
        table.grid th { background: #ffffff; color: #2E2E2E; text-align: left; padding: 5px 4px; font-size: {{ $compact ? '7' : '8' }}px; text-transform: uppercase; border-bottom: 2px solid #2E2E2E; }
        table.grid td { padding: {{ $compact ? '3' : '5' }}px 4px; border-bottom: 1px solid #D7DBE1; vertical-align: top; }
        table.grid thead { display: table-header-group; }
        table.grid tr { page-break-inside: avoid; }
        .num { text-align: right; white-space: nowrap; }
        .code { white-space: nowrap; font-weight: bold; }
        .muted { color: #686868; }
        .total td { font-weight: bold; border-top: 2px solid #0B5D6B; border-bottom: none; }
        .signatures { margin-top: 26px; page-break-inside: avoid; }
        .signatures td { width: 33%; padding: 0 14px 0 0; vertical-align: top; border: none; font-size: 9px; }
        .signatures .box { height: 56px; border-bottom: 1px solid #2E2E2E; margin-top: 6px; }
        #footer { position: fixed; left: 0; right: 0; bottom: -34px; border-top: 1px solid #D7DBE1; padding-top: 5px; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #686868; }
        #footer table td { border: none; padding: 0; }
        @yield('styles')
    </style>
</head>
<body>
    <div id="footer">
        <table>
            <tr>
                <td>{{ $churchName }} · @yield('footer-label', 'Gestion du patrimoine')</td>
            </tr>
        </table>
    </div>

    <table class="head">
        <tr>
            <td style="width: 38px"><div class="logo">{{ $initial }}</div></td>
            <td>
                <div class="church">{{ $churchName }}@if (! empty($churchAcronym)) ({{ $churchAcronym }})@endif</div>
                <div class="sub">Gestion du patrimoine</div>
            </td>
            <td class="right">@yield('meta')</td>
        </tr>
    </table>

    <h1 class="doc-title">@yield('doc-title')</h1>

    @yield('content')
</body>
</html>

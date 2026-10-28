<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Étiquettes</title>
    <style>
        @page { margin: 0; }
        html, body { margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; color: #000000; }
        .label-page { position: relative; margin: 0; padding: 0; }
        .label-slot { position: absolute; overflow: hidden; }
        .label-line { position: absolute; overflow: hidden; white-space: nowrap; }
    </style>
</head>
<body>
    @include('labels.pages', ['pages' => $pages, 'mode' => 'pdf', 'pageWidth' => $pageWidth, 'pageHeight' => $pageHeight])
</body>
</html>

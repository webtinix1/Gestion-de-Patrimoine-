<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('titre') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/etats-systeme.css') }}">
</head>
<body class="etat-page">
    <main class="etat-carte" role="main">
        <span class="etat-nom">{{ config('app.name') }}</span>
        <span class="etat-code @yield('ton')">@yield('code')</span>
        <h1 class="etat-titre">@yield('titre')</h1>
        @yield('contenu')
        <div class="etat-actions">
            @yield('actions')
        </div>
    </main>
</body>
</html>

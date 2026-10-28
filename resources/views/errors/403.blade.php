@php
    $role = null;
    try {
        $role = auth()->user()?->getRoleNames()->first();
    } catch (Throwable) {
    }
    $detail = trim((string) ($exception?->getMessage() ?? ''));
    $detail = $detail === '' || str_contains(strtolower($detail), 'unauthorized') || str_contains(strtolower($detail), 'forbidden') ? null : $detail;
@endphp
@extends('errors.shell')

@section('code', '403')
@section('ton', 'danger')
@section('titre', "Vous n'avez pas accès à cette page")

@section('contenu')
    <p class="etat-texte">
        @if ($role)
            Votre rôle « <strong>{{ $role }}</strong> » ne permet pas cette opération.
        @else
            Cette page ou cette action n'est pas autorisée pour votre compte.
        @endif
        Contactez l'administrateur si besoin.
    </p>
    @if ($detail)
        <p class="etat-texte">{{ $detail }}</p>
    @endif
@endsection

@section('actions')
    <a class="etat-btn primaire" href="{{ url('/admin') }}">Retour à mon espace</a>
@endsection

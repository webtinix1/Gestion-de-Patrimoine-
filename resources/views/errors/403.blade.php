@php
    // Aucune lecture de la session ni de la base : cette page doit s'afficher même si elles sont en panne.
    $detail = null;

    if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        && ! $exception instanceof \Illuminate\Routing\Exceptions\InvalidSignatureException) {
        $message = trim($exception->getMessage());
        $generic = $message === '' || str_contains(strtolower($message), 'unauthorized') || str_contains(strtolower($message), 'forbidden');
        $detail = $generic ? null : $message;
    }
@endphp
@extends('errors.shell')

@section('code', '403')
@section('ton', 'danger')
@section('titre', "Vous n'avez pas accès à cette page")

@section('contenu')
    <p class="etat-texte">Cette page ou cette action n'est pas autorisée pour votre compte. Contactez l'administrateur si besoin.</p>
    @if ($detail)
        <p class="etat-texte">{{ $detail }}</p>
    @endif
@endsection

@section('actions')
    <a class="etat-btn primaire" href="{{ url('/admin') }}">Retour à mon espace</a>
@endsection

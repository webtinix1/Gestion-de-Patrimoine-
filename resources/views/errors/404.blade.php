@extends('errors.shell')

@section('code', '404')
@section('ton', '')
@section('titre', "Cette page n'existe pas")

@section('contenu')
    <p class="etat-texte">Le lien est peut-être obsolète ou l'adresse mal saisie. Vérifiez l'adresse, ou repartez de votre espace.</p>
@endsection

@section('actions')
    <a class="etat-btn primaire" href="{{ url('/admin') }}">Retour à mon espace</a>
@endsection

@extends('errors.shell')

@section('code', '500')
@section('ton', 'danger')
@section('titre', "Une erreur est survenue")

@section('contenu')
    <p class="etat-texte">L'opération n'a pas pu être menée à bien. Vos données n'ont pas été modifiées par cette demande. Réessayez ; si le problème persiste, prévenez l'administrateur en lui indiquant ce que vous faisiez.</p>
@endsection

@section('actions')
    <button type="button" class="etat-btn primaire" onclick="location.reload()">Réessayer</button>
    <a class="etat-btn primaire" href="{{ url('/admin') }}">Retour à mon espace</a>
@endsection

@extends('errors.shell')

@section('code', '503')
@section('ton', 'warning')
@section('titre', "Maintenance en cours")

@section('contenu')
    <p class="etat-texte">L'application est momentanément indisponible (mise à jour ou sauvegarde en cours). Réessayez dans quelques minutes ; vos données sont conservées.</p>
@endsection

@section('actions')
    <button type="button" class="etat-btn primaire" onclick="location.reload()">Réessayer</button>
@endsection

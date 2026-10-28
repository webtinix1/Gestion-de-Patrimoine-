@extends('errors.shell')

@section('code', '429')
@section('ton', 'warning')
@section('titre', "Trop de demandes")

@section('contenu')
    <p class="etat-texte">Trop de demandes en peu de temps. Patientez quelques instants avant de réessayer.</p>
@endsection

@section('actions')
    <button type="button" class="etat-btn primaire" onclick="location.reload()">Réessayer</button>
@endsection

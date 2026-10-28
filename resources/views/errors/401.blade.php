@extends('errors.shell')

@section('code', '401')
@section('ton', 'warning')
@section('titre', "Connexion requise")

@section('contenu')
    <p class="etat-texte">Cette page est réservée aux utilisateurs connectés.</p>
@endsection

@section('actions')
    <a class="etat-btn primaire" href="{{ url('/admin/login') }}">Se connecter</a>
@endsection

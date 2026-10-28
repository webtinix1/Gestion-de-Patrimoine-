@extends('errors.shell')

@section('code', '419')
@section('ton', 'warning')
@section('titre', "Votre session a expiré")

@section('contenu')
    <p class="etat-texte">Par sécurité, vous êtes déconnecté après une période d'inactivité. Reconnectez-vous pour continuer : vous reviendrez sur la page en cours.</p>
@endsection

@section('actions')
    <a class="etat-btn primaire" href="{{ url('/admin/login') }}">Se reconnecter</a>
    <button type="button" class="etat-btn" onclick="history.back()">Page précédente</button>
@endsection

<?php

use Illuminate\Support\Facades\Validator;

it('est configurée en français', function () {
    expect(config('app.locale'))->toBe('fr')
        ->and(app()->getLocale())->toBe('fr')
        ->and(config('app.fallback_locale'))->toBe('fr');
});

it('utilise le fuseau horaire Africa/Brazzaville', function () {
    expect(config('app.timezone'))->toBe('Africa/Brazzaville')
        ->and(date_default_timezone_get())->toBe('Africa/Brazzaville')
        ->and(now()->getTimezone()->getName())->toBe('Africa/Brazzaville');
});

it('traduit les messages de validation en français', function () {
    $validator = Validator::make(
        ['username' => '', 'password' => 'abc'],
        ['username' => 'required', 'password' => 'min:8'],
    );

    expect($validator->errors()->first('username'))->toBe('Le champ identifiant est obligatoire.')
        ->and($validator->errors()->first('password'))->toBe('Le champ mot de passe doit contenir au moins 8 caractères.');
});

it('traduit l\'interface Filament en français', function () {
    expect(__('filament-panels::auth/pages/login.form.password.label'))->toBe('Mot de passe')
        ->and(__('filament-panels::pages/dashboard.title'))->toBe('Tableau de bord');
});

it('fournit les fichiers de langue française de Laravel', function (string $file) {
    expect(lang_path("fr/{$file}.php"))->toBeFile();
})->with(['auth', 'pagination', 'passwords', 'validation']);

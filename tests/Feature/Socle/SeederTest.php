<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

it('crée le compte administrateur de démonstration', function () {
    $this->seed();

    $admin = User::query()->where('username', DemoSeeder::ADMIN_USERNAME)->sole();

    expect($admin->is_active)->toBeTrue()
        ->and(Hash::check(DemoSeeder::ADMIN_PASSWORD, $admin->password))->toBeTrue();
});

it('peut être relancé sans créer de doublon', function () {
    $this->seed();
    $this->seed();

    expect(User::query()->where('username', DemoSeeder::ADMIN_USERNAME)->count())->toBe(1);
});

it('respecte la règle de mot de passe RG-85 pour le compte de démonstration', function () {
    expect(strlen(DemoSeeder::ADMIN_PASSWORD))->toBeGreaterThanOrEqual(8)
        ->and(DemoSeeder::ADMIN_PASSWORD)->toMatch('/\d/');
});

it('ne charge pas les données de démonstration en production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::query()->count())->toBe(0);
});

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration pour les tests et la recette. Jamais chargées en production.
 */
class DemoSeeder extends Seeder
{
    public const string ADMIN_USERNAME = 'admin';

    public const string ADMIN_PASSWORD = 'Patrimoine2026';

    public function run(): void
    {
        User::query()->updateOrCreate(
            ['username' => self::ADMIN_USERNAME],
            [
                'first_name' => 'Administrateur',
                'last_name' => 'Démo',
                'job_title' => 'Administrateur de l\'application',
                'password' => self::ADMIN_PASSWORD,
                'must_change_password' => false,
                'is_active' => true,
            ],
        );
    }
}

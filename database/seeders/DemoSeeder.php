<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Données de démonstration pour les tests et la recette. Jamais chargées en production.
 */
class DemoSeeder extends Seeder
{
    public const string ADMIN_USERNAME = 'admin';

    // Mot de passe commun à tous les comptes de démonstration.
    public const string ADMIN_PASSWORD = 'Patrimoine2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('La démonstration crée des comptes dont le mot de passe est connu : refusée quand APP_ENV vaut production.');
        }

        $admin = User::query()->updateOrCreate(
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
        $admin->syncRoles([DefaultRoles::ADMIN]);

        $this->call([
            StructureDemoSeeder::class,
            UsersDemoSeeder::class,
            AssetsDemoSeeder::class,
            MovementsDemoSeeder::class,
            DepreciationDemoSeeder::class,
            DisposalsDemoSeeder::class,
            AcquisitionsDemoSeeder::class,
            InventoriesDemoSeeder::class,
            OperationsDemoSeeder::class,
            AlertsDemoSeeder::class,
        ]);
    }
}

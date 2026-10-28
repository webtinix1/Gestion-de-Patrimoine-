<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Database\Seeder;

/**
 * Comptes de démonstration de la maquette (E38), couvrant les cinq rôles,
 * avec leur périmètre et les responsables des sites et départements.
 */
class UsersDemoSeeder extends Seeder
{
    /**
     * Identifiant, prénom, nom, rôle, fonction, sites, départements, actif, responsable de.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: list<string>, 6: list<string>, 7: bool, 8: list<string>}>
     */
    public const array USERS = [
        ['p.malonga', 'Pierre', 'Malonga', DefaultRoles::ADMIN, 'Secrétaire général', [], [], true, []],
        ['a.okemba', 'Albertine', 'Okemba', DefaultRoles::MANAGER, 'Responsable logistique et patrimoine', [], [], true, ['site:SIE']],
        ['g.mabiala', 'Grâce', 'Mabiala', DefaultRoles::MANAGER, 'Secrétaire de paroisse', ['MOU'], [], true, ['site:MOU']],
        ['s.ondongo', 'Serge', 'Ondongo', DefaultRoles::MANAGER, 'Responsable d\'annexe', ['TAL'], [], true, ['site:TAL']],
        ['m.loubaki', 'Marcel', 'Loubaki', DefaultRoles::ACCOUNTANT, 'Comptable', [], [], true, ['department:FIN']],
        ['e.mbemba', 'Emmanuel', 'Mbemba', DefaultRoles::DEPARTMENT_HEAD, 'Responsable Louange', [], ['LOU'], true, ['department:LOU']],
        ['d.massamba', 'David', 'Massamba', DefaultRoles::DEPARTMENT_HEAD, 'Responsable Jeunesse', [], ['JEU'], true, ['department:JEU']],
        ['r.bouanga', 'Ruth', 'Bouanga', DefaultRoles::DEPARTMENT_HEAD, 'Responsable Administration', [], ['ADM'], true, ['department:ADM']],
        ['p.itoua', 'Prince', 'Itoua', DefaultRoles::DEPARTMENT_HEAD, 'Responsable Communication', [], ['COM'], true, ['department:COM']],
        ['c.ngoma', 'Christ', 'Ngoma', DefaultRoles::DEPARTMENT_HEAD, 'Responsable Informatique', [], ['INF'], true, ['department:INF', 'site:CFO']],
        ['d.kibangou', 'Dorcas', 'Kibangou', DefaultRoles::DEPARTMENT_HEAD, 'Responsable École du dimanche', [], ['EDD'], true, ['department:EDD']],
        ['j.nkounkou', 'Jonas', 'Nkounkou', DefaultRoles::DEPARTMENT_HEAD, 'Responsable Logistique', [], ['LOG'], true, ['department:LOG']],
        ['p.ngoyi', 'Paul', 'Ngoyi', DefaultRoles::DEPARTMENT_HEAD, 'Responsable Protocole', [], ['PRO'], true, ['department:PRO']],
        ['pasteur', 'Pasteur', '[Nom]', DefaultRoles::VIEWER, 'Pasteur principal', [], [], true, []],
        ['f.bakala', 'Fabrice', 'Bakala', DefaultRoles::DEPARTMENT_HEAD, 'Ancien responsable Communication', [], ['COM'], false, []],
    ];

    public function run(): void
    {
        $sites = Site::query()->withoutGlobalScopes()->pluck('id', 'code');
        $departments = Department::query()->withoutGlobalScopes()->pluck('id', 'code');

        foreach (self::USERS as [$username, $firstName, $lastName, $role, $jobTitle, $siteCodes, $departmentCodes, $active, $manages]) {
            $user = User::query()->updateOrCreate(['username' => $username], [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'job_title' => $jobTitle,
                'password' => DemoSeeder::ADMIN_PASSWORD,
                'must_change_password' => false,
                'is_active' => $active,
            ]);

            $user->syncRoles([$role]);
            $user->sites()->sync(collect($siteCodes)->map(fn (string $code): int => (int) $sites[$code])->all());
            $user->departments()->sync(collect($departmentCodes)->map(fn (string $code): int => (int) $departments[$code])->all());

            foreach ($manages as $target) {
                [$type, $code] = explode(':', $target);
                $model = $type === 'site' ? Site::class : Department::class;
                $model::query()->withoutGlobalScopes()->where('code', $code)->update(['manager_id' => $user->id]);
            }
        }
    }
}

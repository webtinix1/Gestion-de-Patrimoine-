<?php

namespace Database\Seeders;

use App\Enums\RoomType;
use App\Models\Department;
use App\Models\Room;
use App\Models\Site;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Structure de démonstration reprise de la maquette (E34 à E37).
 */
class StructureDemoSeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
     */
    public const array SITES = [
        ['SIE', 'Siège', '[Adresse du siège], Brazzaville', 'Siège administratif et grande salle de culte.', true],
        ['MOU', 'Paroisse Moungali', 'Moungali, Brazzaville', 'Église locale.', true],
        ['TAL', 'Annexe Talangaï', 'Talangaï, Brazzaville', 'Annexe ouverte en 2022.', true],
        ['CFO', 'Centre de formation', '[Adresse], Brazzaville', 'Formations bibliques et informatiques.', true],
        ['MFI', 'Ancienne annexe Mfilou', 'Mfilou, Brazzaville', 'Fermée en 2024, biens transférés à Talangaï.', false],
    ];

    /**
     * Code, nom, sites (null = tous les sites actifs), services.
     *
     * @var list<array{0: string, 1: string, 2: list<string>|null, 3: list<string>}>
     */
    public const array DEPARTMENTS = [
        ['ADM', 'Administration', null, ['Secrétariat général', 'Accueil', 'Archives']],
        ['FIN', 'Finances', ['SIE'], ['Comptabilité', 'Trésorerie']],
        ['COM', 'Communication', ['SIE', 'MOU'], ['Audiovisuel', 'Médias sociaux', 'Imprimerie']],
        ['INF', 'Informatique', ['SIE'], ['Support', 'Réseau']],
        ['LOU', 'Louange', null, ['Chorale', 'Orchestre', 'Danse']],
        ['JEU', 'Jeunesse', null, ['Culte des jeunes', 'Sports']],
        ['EDD', 'École du dimanche', ['SIE', 'MOU'], ['Petits', 'Grands']],
        ['PRO', 'Protocole', ['SIE'], ['Réceptions', 'Accueil des cultes']],
        ['LOG', 'Logistique', null, ['Magasin', 'Transport', 'Maintenance']],
    ];

    /**
     * Site, nom, code, type, département.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: RoomType, 4: string|null}>
     */
    public const array ROOMS = [
        ['SIE', 'Salle de culte', 'SIE-CUL', RoomType::WorshipHall, null],
        ['SIE', 'Bureau administratif', 'SIE-ADM', RoomType::Office, 'ADM'],
        ['SIE', 'Bureau comptabilité', 'SIE-CPT', RoomType::Office, 'FIN'],
        ['SIE', 'Salle de réunion', 'SIE-REU', RoomType::MeetingRoom, null],
        ['SIE', 'Salle informatique', 'SIE-INF', RoomType::ComputerRoom, 'INF'],
        ['SIE', 'Magasin central', 'SIE-MAG', RoomType::Storeroom, 'LOG'],
        ['SIE', 'Salle polyvalente', 'SIE-POL', RoomType::TrainingRoom, 'JEU'],
        ['SIE', 'Local technique', 'SIE-TEC', RoomType::TechnicalRoom, 'LOG'],
        ['MOU', 'Salle de culte', 'MOU-CUL', RoomType::WorshipHall, null],
        ['MOU', 'Secrétariat', 'MOU-SEC', RoomType::Office, 'ADM'],
        ['MOU', 'Salle École du dimanche', 'MOU-EDD', RoomType::TrainingRoom, 'EDD'],
        ['TAL', 'Salle de culte', 'TAL-CUL', RoomType::WorshipHall, null],
        ['TAL', 'Bureau du responsable', 'TAL-BUR', RoomType::Office, 'ADM'],
        ['TAL', 'Magasin', 'TAL-MAG', RoomType::Storeroom, null],
        ['CFO', 'Salle de formation 1', 'CFO-SF1', RoomType::TrainingRoom, null],
        ['CFO', 'Salle informatique', 'CFO-INF', RoomType::ComputerRoom, 'INF'],
    ];

    /**
     * @var list<string>
     */
    public const array SUPPLIERS = [
        'Bureau Vision Congo',
        'Music Pro Brazzaville',
        'Informatique Plus',
        'Froid Service Congo',
        'Meubles du Pool',
        'Énergie Solaire Plus',
        'Garage Mavoungou',
    ];

    public function run(): void
    {
        $sites = collect(self::SITES)->mapWithKeys(fn (array $site): array => [
            $site[0] => Site::query()->updateOrCreate(
                ['code' => $site[0]],
                ['name' => $site[1], 'address' => $site[2], 'description' => $site[3], 'is_active' => $site[4]],
            ),
        ]);

        $activeSiteIds = $sites->filter(fn (Site $site): bool => $site->is_active)->pluck('id');

        $departments = collect(self::DEPARTMENTS)->mapWithKeys(function (array $definition) use ($sites, $activeSiteIds): array {
            [$code, $name, $siteCodes, $services] = $definition;

            $department = Department::query()->updateOrCreate(['code' => $code], ['name' => $name]);
            $department->sites()->sync($siteCodes === null
                ? $activeSiteIds
                : collect($siteCodes)->map(fn (string $siteCode): int => $sites[$siteCode]->id));

            foreach ($services as $serviceName) {
                $department->services()->firstOrCreate(['name' => $serviceName]);
            }

            return [$code => $department];
        });

        foreach (self::ROOMS as [$siteCode, $name, $code, $type, $departmentCode]) {
            Room::query()->updateOrCreate(['code' => $code], [
                'site_id' => $sites[$siteCode]->id,
                'name' => $name,
                'type' => $type,
                'department_id' => $departmentCode ? $departments[$departmentCode]->id : null,
            ]);
        }

        foreach (self::SUPPLIERS as $name) {
            Supplier::query()->firstOrCreate(['name' => $name]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Actions\Assets\ApplyAssetRules;
use App\Enums\AssetStatus;
use App\Enums\FundingSource;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Holder;
use App\Models\Room;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AssetNumberGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Biens de démonstration : environ 300 biens répartis sur la structure de démonstration,
 * dont les biens des cas d'amortissement de tests/Fixtures/amortissements.json.
 * Idempotent (un numéro déjà présent n'est pas recréé) et déterministe. Jamais chargé en production.
 */
class AssetsDemoSeeder extends Seeder
{
    private const int RANDOM_SEED = 20261027;

    /**
     * Responsables de démonstration : nom, département, identifiant du compte lié (ou null).
     *
     * @var list<array{0: string, 1: string, 2: string|null}>
     */
    public const array HOLDERS = [
        ['Ruth Bouanga', 'ADM', 'r.bouanga'],
        ['Marcel Loubaki', 'FIN', 'm.loubaki'],
        ['Prince Itoua', 'COM', 'p.itoua'],
        ['Christ Ngoma', 'INF', 'c.ngoma'],
        ['Emmanuel Mbemba', 'LOU', 'e.mbemba'],
        ['David Massamba', 'JEU', 'd.massamba'],
        ['Dorcas Kibangou', 'EDD', 'd.kibangou'],
        ['Jonas Nkounkou', 'LOG', 'j.nkounkou'],
        ['Paul Ngoyi', 'PRO', 'p.ngoyi'],
        ['Esther Moukala', 'COM', null],
        ['Gildas Tchicaya', 'LOG', null],
        ['Bénédicte Makosso', 'LOU', null],
        ['Rodrigue Samba', 'JEU', null],
    ];

    /**
     * Biens imposés (cas A2, A5, A3 de tests/Fixtures/amortissements.json et console de mixage).
     *
     * @var list<array<string, mixed>>
     */
    public const array FIXED = [
        [
            'number' => 'PAT-000125', 'category' => 'INF', 'designation' => 'Ordinateur portable', 'brand' => 'HP', 'model' => 'EliteBook 840',
            'cost' => 540000, 'duration' => 3, 'acquired_on' => '2024-03-18', 'in_service_on' => '2024-03-18',
            'room' => 'SIE-ADM', 'department' => 'ADM', 'service' => 'Secrétariat général', 'condition' => 'Bon état', 'holder' => 'Ruth Bouanga',
            'status' => AssetStatus::InService,
        ],
        [
            'number' => 'PAT-000034', 'category' => 'ELE', 'designation' => 'Groupe électrogène', 'brand' => 'SDMO', 'model' => '15 kVA',
            'cost' => 4800000, 'duration' => 8, 'acquired_on' => '2022-06-15', 'in_service_on' => '2022-06-15',
            'room' => 'SIE-TEC', 'department' => 'LOG', 'service' => 'Maintenance', 'condition' => 'Hors service', 'holder' => 'Jonas Nkounkou',
            // La sortie du patrimoine viendra au lot 10 : le statut reste « En service ».
            'status' => AssetStatus::InService,
        ],
        [
            'number' => 'PAT-001248', 'category' => 'AUD', 'designation' => 'Vidéoprojecteur', 'brand' => 'Epson', 'model' => 'EB-X51',
            'cost' => 385000, 'duration' => 5, 'acquired_on' => '2026-10-22', 'in_service_on' => '2026-10-26',
            'room' => 'SIE-CUL', 'department' => 'COM', 'service' => 'Audiovisuel', 'condition' => 'Neuf', 'holder' => 'Prince Itoua',
            'status' => AssetStatus::InService,
        ],
        [
            'number' => 'PAT-000087', 'category' => 'SON', 'designation' => 'Console de mixage', 'brand' => 'Yamaha', 'model' => 'MG16XU',
            'cost' => 450000, 'duration' => 5, 'acquired_on' => '2023-05-20', 'in_service_on' => '2023-05-20',
            'room' => 'SIE-POL', 'department' => 'JEU', 'service' => 'Culte des jeunes', 'condition' => 'Très bon état', 'holder' => 'David Massamba',
            'status' => AssetStatus::InService,
        ],
    ];

    /**
     * Modèles de biens : catégorie, désignation, marque, modèle, prix min, prix max, quantité,
     * emplacements « local:département » utilisés à tour de rôle, fournisseur (null = selon la catégorie).
     *
     * @var list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: int, 5: int, 6: int, 7: list<string>, 8: string|null}>
     */
    public const array TEMPLATES = [
        ['INF', 'Ordinateur portable', 'HP', 'ProBook 450', 380000, 650000, 14, ['SIE-ADM:ADM', 'SIE-CPT:FIN', 'SIE-INF:INF', 'MOU-SEC:ADM', 'TAL-BUR:ADM', 'CFO-INF:INF'], null],
        ['INF', 'Ordinateur de bureau', 'Dell', 'OptiPlex 3080', 300000, 500000, 12, ['SIE-INF:INF', 'CFO-INF:INF', 'SIE-CPT:FIN', 'SIE-ADM:ADM'], null],
        ['INF', 'Imprimante laser', 'HP', 'LaserJet Pro M404', 150000, 280000, 8, ['SIE-ADM:ADM', 'SIE-CPT:FIN', 'MOU-SEC:ADM', 'SIE-REU:ADM'], null],
        ['INF', 'Commutateur réseau 24 ports', 'Cisco', 'SG350-28', 250000, 420000, 4, ['SIE-INF:INF'], null],
        ['INF', 'Onduleur', 'APC', 'Back-UPS 1500', 110000, 220000, 8, ['SIE-INF:INF', 'SIE-CPT:FIN', 'SIE-ADM:ADM', 'CFO-INF:INF'], null],
        ['INF', 'Tablette', 'Samsung', 'Galaxy Tab A8', 140000, 220000, 4, ['SIE-CUL:COM', 'SIE-ADM:ADM'], null],
        ['MOB', 'Bureau de direction', null, null, 180000, 350000, 8, ['SIE-ADM:ADM', 'SIE-CPT:FIN', 'MOU-SEC:ADM', 'TAL-BUR:ADM'], null],
        ['MOB', 'Armoire métallique', null, null, 90000, 160000, 8, ['SIE-ADM:ADM', 'SIE-CPT:FIN', 'MOU-SEC:ADM', 'SIE-MAG:LOG'], null],
        ['MOB', 'Fauteuil de bureau', null, null, 60000, 140000, 12, ['SIE-ADM:ADM', 'SIE-CPT:FIN', 'SIE-REU:ADM', 'MOU-SEC:ADM', 'TAL-BUR:ADM', 'SIE-INF:INF'], null],
        ['MOB', 'Table de réunion', null, null, 150000, 300000, 4, ['SIE-REU:ADM', 'CFO-SF1:ADM'], null],
        ['MOB', 'Lot de 20 chaises empilables', null, null, 120000, 200000, 9, ['SIE-POL:JEU', 'MOU-EDD:EDD', 'CFO-SF1:JEU', 'SIE-REU:ADM'], null],
        ['MOB', 'Banc de culte', null, null, 70000, 120000, 10, ['SIE-CUL:PRO', 'MOU-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['MOB', 'Étagère de rangement', null, null, 50000, 110000, 6, ['SIE-MAG:LOG', 'TAL-MAG:LOG', 'SIE-ADM:ADM'], null],
        ['AUD', 'Vidéoprojecteur', 'Epson', 'EB-W49', 330000, 520000, 8, ['SIE-CUL:COM', 'MOU-CUL:COM', 'TAL-CUL:JEU', 'SIE-REU:ADM', 'CFO-SF1:ADM'], null],
        ['AUD', 'Écran de projection', 'Lumi', 'Motorisé 3 m', 120000, 250000, 5, ['SIE-CUL:COM', 'MOU-CUL:COM', 'SIE-REU:ADM'], null],
        ['AUD', 'Caméra de streaming', 'Sony', 'FDR-AX43', 650000, 1200000, 3, ['SIE-CUL:COM'], null],
        ['AUD', 'Téléviseur', 'LG', '55UQ7500', 280000, 450000, 5, ['SIE-REU:ADM', 'SIE-POL:JEU', 'MOU-EDD:EDD'], null],
        ['AUD', 'Appareil photo', 'Canon', 'EOS 250D', 380000, 520000, 2, ['SIE-CUL:COM'], null],
        ['SON', 'Console de mixage', 'Yamaha', 'MG12XU', 300000, 450000, 3, ['SIE-CUL:LOU', 'MOU-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['SON', 'Microphone sans fil', 'Shure', 'SLX24', 150000, 260000, 12, ['SIE-CUL:LOU', 'MOU-CUL:LOU', 'TAL-CUL:LOU', 'SIE-POL:JEU'], null],
        ['SON', 'Enceinte active', 'JBL', 'PRX815', 480000, 780000, 10, ['SIE-CUL:LOU', 'MOU-CUL:LOU', 'TAL-CUL:LOU', 'SIE-POL:JEU'], null],
        ['SON', 'Amplificateur de puissance', 'Crown', 'XLS 1500', 520000, 850000, 4, ['SIE-CUL:LOU', 'MOU-CUL:LOU'], null],
        ['SON', 'Caisson de basses', 'JBL', 'PRX818XLFW', 400000, 650000, 4, ['SIE-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['SON', 'Piano numérique', 'Yamaha', 'P-125', 450000, 650000, 3, ['SIE-CUL:LOU', 'MOU-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['SON', 'Clavier arrangeur', 'Yamaha', 'PSR-SX700', 800000, 1100000, 2, ['SIE-CUL:LOU', 'MOU-CUL:LOU'], null],
        ['SON', 'Batterie acoustique', 'Pearl', 'Roadshow', 380000, 520000, 3, ['SIE-CUL:LOU', 'MOU-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['ELE', 'Groupe électrogène', 'Kipor', 'KDE6700', 1200000, 2200000, 2, ['SIE-TEC:LOG', 'TAL-MAG:LOG'], null],
        ['ELE', 'Climatiseur split 18 000 BTU', 'LG', 'S18', 380000, 650000, 16, ['SIE-CUL:PRO', 'MOU-CUL:LOU', 'TAL-CUL:LOU', 'SIE-ADM:ADM', 'SIE-REU:ADM', 'SIE-INF:INF', 'CFO-INF:INF', 'CFO-SF1:ADM'], 'Froid Service Congo'],
        ['ELE', 'Ventilateur sur pied', 'Binatone', 'FS-1620', 45000, 85000, 8, ['SIE-POL:JEU', 'MOU-EDD:EDD', 'TAL-CUL:JEU', 'CFO-SF1:ADM'], null],
        ['ELE', 'Kit solaire 2 kVA', 'Victron', 'MultiPlus 2000', 1400000, 2400000, 2, ['SIE-TEC:LOG', 'TAL-MAG:LOG'], null],
        ['BUR', 'Photocopieur multifonction', 'Canon', 'iR2425', 900000, 1600000, 3, ['SIE-ADM:ADM', 'MOU-SEC:ADM', 'SIE-CPT:FIN'], null],
        ['BUR', 'Coffre-fort', null, null, 150000, 320000, 3, ['SIE-CPT:FIN', 'SIE-ADM:ADM', 'MOU-SEC:ADM'], null],
        ['BUR', 'Calculatrice de bureau', 'Casio', 'DJ-240D', 15000, 30000, 8, ['SIE-CPT:FIN', 'SIE-ADM:ADM', 'MOU-SEC:ADM'], null],
        ['BUR', 'Téléphone fixe', 'Panasonic', 'KX-TS500', 20000, 45000, 8, ['SIE-ADM:ADM', 'SIE-CPT:FIN', 'MOU-SEC:ADM', 'TAL-BUR:ADM'], null],
        ['BUR', 'Destructeur de documents', 'Fellowes', 'Powershred 79Ci', 70000, 130000, 4, ['SIE-CPT:FIN', 'SIE-ADM:ADM'], null],
        ['TRA', 'Véhicule utilitaire', 'Toyota', 'Hiace', 18000000, 24000000, 1, ['SIE-MAG:LOG'], null],
        ['TRA', 'Minibus', 'Toyota', 'Coaster', 32000000, 38000000, 1, ['SIE-MAG:LOG'], null],
        ['TRA', 'Moto', 'Yamaha', 'AG100', 750000, 1100000, 2, ['SIE-MAG:LOG', 'TAL-MAG:LOG'], null],
        ['CUL', 'Tenue de chorale (lot de 20 toges)', null, null, 150000, 300000, 6, ['SIE-CUL:LOU', 'MOU-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['CUL', 'Chaire', null, null, 250000, 450000, 3, ['SIE-CUL:PRO', 'MOU-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['CUL', 'Pupitre', null, null, 80000, 150000, 4, ['SIE-CUL:PRO', 'MOU-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['CUL', 'Chandelier', null, null, 20000, 45000, 6, ['SIE-CUL:PRO', 'MOU-CUL:LOU', 'TAL-CUL:LOU'], null],
        ['CUL', 'Bannière', null, null, 35000, 70000, 5, ['SIE-CUL:PRO', 'MOU-CUL:LOU', 'TAL-CUL:JEU'], null],
        ['CUL', 'Tapis de culte', null, null, 90000, 200000, 4, ['SIE-CUL:PRO', 'MOU-CUL:LOU'], null],
        ['CUI', 'Réfrigérateur', 'Hisense', 'RR220D', 220000, 420000, 4, ['SIE-MAG:LOG', 'TAL-MAG:LOG', 'MOU-EDD:EDD'], null],
        ['CUI', 'Cuisinière à gaz 5 feux', 'Beko', 'GG15', 180000, 320000, 2, ['SIE-MAG:LOG', 'TAL-MAG:LOG'], null],
        ['CUI', 'Marmite en inox', null, null, 40000, 95000, 6, ['SIE-MAG:LOG', 'TAL-MAG:LOG'], null],
        ['CUI', 'Bouilloire électrique', 'Moulinex', 'Subito', 15000, 35000, 4, ['SIE-REU:ADM', 'MOU-SEC:ADM'], null],
        ['CUI', 'Congélateur coffre', 'Hisense', 'FC290', 280000, 480000, 2, ['SIE-MAG:LOG', 'TAL-MAG:LOG'], null],
        ['AUT', 'Tente de réception', null, null, 300000, 600000, 3, ['SIE-MAG:LOG', 'TAL-MAG:LOG'], null],
        ['AUT', 'Extincteur', null, null, 25000, 45000, 12, ['SIE-CUL:PRO', 'MOU-CUL:LOU', 'TAL-CUL:LOU', 'SIE-ADM:ADM', 'SIE-TEC:LOG', 'CFO-SF1:ADM'], null],
        ['AUT', 'Panneau d\'affichage', null, null, 40000, 90000, 4, ['SIE-ADM:ADM', 'MOU-SEC:ADM', 'TAL-BUR:ADM'], null],
        ['AUT', 'Podium modulable', null, null, 450000, 750000, 2, ['SIE-CUL:PRO'], null],
    ];

    /**
     * Fournisseur par défaut selon la catégorie.
     *
     * @var array<string, string>
     */
    private const array SUPPLIER_BY_CATEGORY = [
        'INF' => 'Informatique Plus',
        'MOB' => 'Meubles du Pool',
        'AUD' => 'Music Pro Brazzaville',
        'SON' => 'Music Pro Brazzaville',
        'ELE' => 'Énergie Solaire Plus',
        'BUR' => 'Bureau Vision Congo',
        'TRA' => 'Garage Mavoungou',
        'CUL' => 'Meubles du Pool',
        'CUI' => 'Froid Service Congo',
        'AUT' => 'Bureau Vision Congo',
    ];

    /**
     * Tirage pondéré de l'état d'un bien en service.
     *
     * @var array<string, int>
     */
    private const array CONDITION_WEIGHTS = [
        'Neuf' => 12,
        'Très bon état' => 28,
        'Bon état' => 42,
        'État moyen' => 14,
        'À réparer' => 4,
    ];

    /** @var array<string, AssetCategory> */
    private array $categories = [];

    /** @var array<string, int> */
    private array $conditions = [];

    /** @var array<string, int> */
    private array $suppliers = [];

    /** @var array<string, Room> */
    private array $rooms = [];

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, Holder> */
    private array $holders = [];

    public function run(): void
    {
        $this->load();

        $apply = app(ApplyAssetRules::class);
        $fixedNumbers = array_column(self::FIXED, 'number');
        $highest = 0;

        // Les journaux de création n'ont pas de sens pour des données de démonstration.
        activity()->withoutLogs(function () use ($apply, $fixedNumbers, &$highest): void {
            mt_srand(self::RANDOM_SEED);

            foreach (self::FIXED as $fixed) {
                $highest = max($highest, (int) substr((string) $fixed['number'], -6));
                $this->store($apply, $this->fixedData($fixed), (string) $fixed['number']);
            }

            $counter = 0;

            foreach (self::TEMPLATES as $template) {
                for ($i = 0; $i < $template[6]; $i++) {
                    do {
                        $counter++;
                        $number = 'PAT-'.str_pad((string) $counter, AssetNumberGenerator::DIGITS, '0', STR_PAD_LEFT);
                    } while (in_array($number, $fixedNumbers, true));

                    $highest = max($highest, $counter);
                    $this->store($apply, $this->generatedData($template, $i), $number);
                }
            }

            mt_srand();
        });

        // Le prochain bien créé à l'écran prend la suite du plus grand numéro de démonstration.
        app(AssetNumberGenerator::class)->syncWith('global', $highest);
    }

    private function load(): void
    {
        $this->categories = AssetCategory::query()->get()->keyBy('prefix')->all();
        $this->conditions = AssetCondition::query()->pluck('id', 'label')->all();
        $this->suppliers = Supplier::query()->pluck('id', 'name')->all();
        $this->rooms = Room::query()->withoutGlobalScopes()->get()->keyBy('code')->all();
        $this->departments = Department::query()->withoutGlobalScopes()->get()->keyBy('code')->all();

        foreach (self::HOLDERS as [$name, $departmentCode, $username]) {
            $userId = $username === null ? null : User::query()->where('username', $username)->value('id');

            $this->holders[$name] = Holder::query()->updateOrCreate(['name' => $name], [
                'department_id' => $this->departments[$departmentCode]->id,
                'user_id' => $userId,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(ApplyAssetRules $apply, array $data, string $number): void
    {
        if (Asset::query()->withoutGlobalScopes()->where('number', $number)->exists()) {
            return;
        }

        $category = $this->categories[(string) $data['category_prefix']];
        unset($data['category_prefix']);

        $asset = new Asset($apply($data, $category));
        $asset->forceFill(['number' => $number]);
        $asset->save();
    }

    /**
     * @param  array<string, mixed>  $fixed
     * @return array<string, mixed>
     */
    private function fixedData(array $fixed): array
    {
        $room = $this->rooms[(string) $fixed['room']];
        $department = $this->departments[(string) $fixed['department']];

        return [
            'category_prefix' => $fixed['category'],
            'asset_category_id' => $this->categories[(string) $fixed['category']]->id,
            'designation' => $fixed['designation'],
            'brand' => $fixed['brand'],
            'model' => $fixed['model'],
            'acquired_on' => $fixed['acquired_on'],
            'in_service_on' => $fixed['in_service_on'],
            'assigned_since' => $fixed['in_service_on'],
            'supplier_id' => $this->suppliers[self::SUPPLIER_BY_CATEGORY[(string) $fixed['category']]] ?? null,
            'acquisition_cost' => $fixed['cost'],
            'duration_years' => $fixed['duration'],
            'funding_source' => FundingSource::OwnFunds,
            'site_id' => $room->site_id,
            'department_id' => $department->id,
            'service_id' => $this->serviceId($department, (string) $fixed['service']),
            'room_id' => $room->id,
            'holder_id' => $this->holders[(string) $fixed['holder']]->id,
            'asset_condition_id' => $this->conditions[(string) $fixed['condition']],
            'status' => $fixed['status'],
        ];
    }

    /**
     * @param  array{0: string, 1: string, 2: string|null, 3: string|null, 4: int, 5: int, 6: int, 7: list<string>, 8: string|null}  $template
     * @return array<string, mixed>
     */
    private function generatedData(array $template, int $index): array
    {
        [$prefix, $designation, $brand, $model, $min, $max, , $placements, $supplier] = $template;

        $roll = mt_rand(1, 100);
        $status = match (true) {
            $roll <= 6 => AssetStatus::InStock,
            $roll <= 10 => AssetStatus::OutOfService,
            default => AssetStatus::InService,
        };

        [$roomCode, $departmentCode] = explode(':', $placements[$index % count($placements)]);

        if ($status === AssetStatus::InStock) {
            // En stock : le bien attend au magasin.
            $roomCode = mt_rand(0, 1) === 0 ? 'SIE-MAG' : 'TAL-MAG';
            $departmentCode = 'LOG';
        }

        $room = $this->rooms[$roomCode];
        $department = $this->departments[$departmentCode];
        $acquiredOn = Carbon::create(2019, 1, 1)->addDays(mt_rand(0, 2830));
        $inServiceOn = $status === AssetStatus::InStock ? null : $acquiredOn->copy()->addDays(mt_rand(0, 14));

        $condition = match ($status) {
            AssetStatus::OutOfService => mt_rand(0, 1) === 0 ? 'Hors service' : 'À réparer',
            AssetStatus::InStock => ['Neuf', 'Très bon état', 'Bon état'][mt_rand(0, 2)],
            default => $this->weightedCondition(),
        };

        $service = $this->randomServiceId($department);
        $holders = $this->holdersOf($department);
        $holder = $holders !== [] && mt_rand(1, 100) <= 60 ? $holders[mt_rand(0, count($holders) - 1)] : null;
        $withInvoice = mt_rand(1, 100) <= 70;

        return [
            'category_prefix' => $prefix,
            'asset_category_id' => $this->categories[$prefix]->id,
            'designation' => $designation,
            'brand' => $brand,
            'model' => $model,
            'serial_number' => in_array($prefix, ['INF', 'AUD', 'SON', 'ELE', 'TRA'], true) ? $this->serialNumber() : null,
            'acquired_on' => $acquiredOn->toDateString(),
            'in_service_on' => $inServiceOn?->toDateString(),
            'assigned_since' => $acquiredOn->toDateString(),
            'supplier_id' => $this->suppliers[$supplier ?? self::SUPPLIER_BY_CATEGORY[$prefix]] ?? null,
            'invoice_reference' => $withInvoice ? sprintf('FAC-%d-%04d', $acquiredOn->year, mt_rand(1, 999)) : null,
            'acquisition_cost' => $this->price($min, $max),
            'funding_source' => $this->fundingSource(),
            'site_id' => $room->site_id,
            'department_id' => $department->id,
            'service_id' => $service,
            'room_id' => $room->id,
            'holder_id' => $holder?->id,
            'asset_condition_id' => $this->conditions[$condition],
            'status' => $status,
        ];
    }

    private function weightedCondition(): string
    {
        $pick = mt_rand(1, array_sum(self::CONDITION_WEIGHTS));

        foreach (self::CONDITION_WEIGHTS as $label => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return $label;
            }
        }

        return 'Bon état';
    }

    private function price(int $min, int $max): int
    {
        $step = match (true) {
            $max >= 1000000 => 10000,
            $max >= 100000 => 5000,
            default => 500,
        };

        return intdiv(mt_rand($min, $max), $step) * $step;
    }

    private function fundingSource(): FundingSource
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 70 => FundingSource::OwnFunds,
            $roll <= 90 => FundingSource::EarmarkedDonation,
            default => FundingSource::Grant,
        };
    }

    private function serialNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
        $serial = '';

        for ($i = 0; $i < 10; $i++) {
            $serial .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }

        return $serial;
    }

    private function serviceId(Department $department, string $name): ?int
    {
        $id = Service::query()->withoutGlobalScopes()
            ->where('department_id', $department->id)
            ->where('name', $name)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function randomServiceId(Department $department): ?int
    {
        if (mt_rand(1, 100) > 65) {
            return null;
        }

        /** @var list<int> $ids */
        $ids = Service::query()->withoutGlobalScopes()->where('department_id', $department->id)->orderBy('id')->pluck('id')->all();

        return $ids === [] ? null : $ids[mt_rand(0, count($ids) - 1)];
    }

    /**
     * @return list<Holder>
     */
    private function holdersOf(Department $department): array
    {
        return array_values(array_filter(
            $this->holders,
            fn (Holder $holder): bool => $holder->department_id === $department->id,
        ));
    }
}

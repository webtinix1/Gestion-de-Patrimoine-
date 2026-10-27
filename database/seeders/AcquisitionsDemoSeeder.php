<?php

namespace Database\Seeders;

use App\Enums\AcquisitionStatus;
use App\Enums\FundingSource;
use App\Models\Acquisition;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Site;
use App\Models\Supplier;
use App\Services\AcquisitionReferenceGenerator;
use Illuminate\Database\Seeder;

/**
 * Acquisitions de la maquette (E11, E12). Idempotent. Jamais chargé en production.
 *
 * ACQ-2026-038 est validée et reliée au vidéoprojecteur PAT-001248 déjà présent. Les autres sont
 * des brouillons : les valider crée des fiches (ACQ-2026-039 reproduit la maquette, PAT-001249 à PAT-001254).
 * Aucune fiche n'est créée par ce seeder, pour garder les 300 biens et le prochain numéro de la démonstration.
 */
class AcquisitionsDemoSeeder extends Seeder
{
    /**
     * Référence, date, fournisseur, facture, département, site, financement, lignes (désignation, catégorie, quantité, prix, suivi).
     *
     * @var list<array<string, mixed>>
     */
    private const array ACQUISITIONS = [
        [
            'reference' => 'ACQ-2026-039', 'date' => '2026-10-26', 'supplier' => 'Informatique Plus Brazzaville', 'invoice' => 'FAC-2026-1003',
            'department' => 'INF', 'site' => 'SIE', 'funding' => FundingSource::OwnFunds, 'status' => AcquisitionStatus::Draft,
            'lines' => [
                ['Ordinateur portable HP ProBook 450', 'INF', 3, 600000, true],
                ['Onduleur APC 1500 VA', 'ELE', 3, 145000, true],
                ['Câbles HDMI 3 m', 'AUT', 6, 7500, false],
            ],
        ],
        [
            'reference' => 'ACQ-2026-038', 'date' => '2026-10-22', 'supplier' => 'Bureau Vision Congo', 'invoice' => 'BVC-F-2026-1142',
            'department' => 'COM', 'site' => 'SIE', 'funding' => FundingSource::OwnFunds, 'status' => AcquisitionStatus::Validated,
            'lines' => [['Vidéoprojecteur', 'AUD', 1, 385000, true]], 'asset' => 'PAT-001248',
        ],
        [
            'reference' => 'ACQ-2026-037', 'date' => '2026-10-22', 'supplier' => 'Music Pro Brazzaville', 'invoice' => 'MP-7781',
            'department' => 'LOU', 'site' => 'SIE', 'funding' => FundingSource::EarmarkedDonation, 'status' => AcquisitionStatus::Draft,
            'lines' => [['Microphone sans fil', 'SON', 2, 410000, true]],
        ],
        [
            'reference' => 'ACQ-2026-036', 'date' => '2026-10-15', 'supplier' => 'Informatique Plus', 'invoice' => 'FAC-2026-0912',
            'department' => 'FIN', 'site' => 'SIE', 'funding' => FundingSource::OwnFunds, 'status' => AcquisitionStatus::Draft,
            'lines' => [['Ordinateur de bureau', 'INF', 5, 348000, true]],
        ],
    ];

    public function run(): void
    {
        $categories = AssetCategory::query()->get()->keyBy('prefix');
        $sites = Site::query()->withoutGlobalScopes()->get()->keyBy('code');
        $departments = Department::query()->withoutGlobalScopes()->get()->keyBy('code');

        activity()->withoutLogs(function () use ($categories, $sites, $departments): void {
            foreach (self::ACQUISITIONS as $row) {
                $site = $sites[(string) $row['site']] ?? null;
                $department = $departments[(string) $row['department']] ?? null;

                if ($site === null || $department === null) {
                    continue;
                }

                $lines = collect($row['lines'])
                    ->filter(fn (array $line): bool => isset($categories[(string) $line[1]]))
                    ->map(fn (array $line): array => [
                        'designation' => $line[0],
                        'asset_category_id' => $categories[(string) $line[1]]->id,
                        'quantity' => $line[2],
                        'unit_price' => $line[3],
                        'tracked_individually' => $line[4],
                        'line_total' => $line[2] * $line[3],
                    ]);

                $acquisition = Acquisition::query()->withoutGlobalScopes()->firstOrNew(['reference' => $row['reference']]);

                if ($acquisition->exists) {
                    continue;
                }

                $acquisition->fill([
                    'acquired_on' => $row['date'],
                    'supplier_id' => Supplier::query()->firstOrCreate(['name' => $row['supplier']], ['is_active' => true])->id,
                    'invoice_reference' => $row['invoice'],
                    'funding_source' => $row['funding'],
                    'site_id' => $site->id,
                    'department_id' => $department->id,
                    'total_amount' => $lines->sum('line_total'),
                ]);
                $acquisition->forceFill(['reference' => $row['reference'], 'status' => $row['status']]);

                if ($row['status'] === AcquisitionStatus::Validated) {
                    $acquisition->forceFill(['validated_at' => $row['date'].' 12:00:00']);
                }

                $acquisition->save();
                $acquisition->lines()->createMany($lines->all());

                if (isset($row['asset'])) {
                    Asset::query()->withoutGlobalScopes()
                        ->where('number', $row['asset'])
                        ->update(['acquisition_line_id' => $acquisition->lines()->value('id')]);
                }
            }
        });

        app(AcquisitionReferenceGenerator::class)->syncWith(2026, 39);
    }
}

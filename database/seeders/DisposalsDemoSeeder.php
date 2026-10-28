<?php

namespace Database\Seeders;

use App\Actions\Disposals\RecordDisposal;
use App\Actions\Disposals\ValidateDisposal;
use App\Enums\AssetStatus;
use App\Enums\DisposalReason;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Démonstration des sorties (après les amortissements) : 3 sorties enregistrées par l'action métier,
 * une validée par le comptable, deux à valider. PAT-000034 sort le 26/10/2026 : cas A5 exact
 * (dotation 2026 = 493 333, cumul 2 620 000, VNC 2 180 000). Idempotent. Jamais chargé en production.
 */
class DisposalsDemoSeeder extends Seeder
{
    /**
     * Repère (référence de la décision), bien (numéro ou catégorie), données, validée ?
     *
     * @var list<array{decision: string, number?: string, category?: string, reason: DisposalReason, disposed_on: string, decided_by: string, counterparty?: string, notes: string, validated: bool}>
     */
    public const array DISPOSALS = [
        [
            'decision' => 'Lettre du pasteur du 28/09/2026', 'category' => 'INF', 'reason' => DisposalReason::Donation,
            'disposed_on' => '2026-09-30', 'decided_by' => 'Pasteur principal', 'counterparty' => 'École primaire de Mfilou',
            'notes' => 'Don à l\'école voisine, poste remplacé en 2026.', 'validated' => true,
        ],
        [
            'decision' => 'PV CA du 18/10/2026', 'category' => 'SON', 'reason' => DisposalReason::Loss,
            'disposed_on' => '2026-10-20', 'decided_by' => 'Conseil d\'administration',
            'notes' => 'Non retrouvé après la convention du 12/10/2026 ; constat le 20/10/2026.', 'validated' => false,
        ],
        [
            'decision' => 'PV CA du 24/10/2026', 'number' => 'PAT-000034', 'reason' => DisposalReason::Retirement,
            'disposed_on' => '2026-10-26', 'decided_by' => 'Conseil d\'administration',
            'notes' => 'Déclaré irréparable par le prestataire. Pièces récupérables stockées au magasin.', 'validated' => false,
        ],
    ];

    public function run(): void
    {
        $manager = User::query()->where('username', 'a.okemba')->first();
        $accountant = User::query()->where('username', 'm.loubaki')->first();
        $previous = auth()->user();

        foreach (self::DISPOSALS as $entry) {
            if (Disposal::query()->withoutGlobalScopes()->where('decision_reference', $entry['decision'])->exists()) {
                continue;
            }

            $asset = $this->asset($entry);

            if (! $asset instanceof Asset) {
                continue;
            }

            if ($manager instanceof User) {
                auth()->setUser($manager);
            }

            $disposal = app(RecordDisposal::class)($asset, [
                'disposed_on' => $entry['disposed_on'],
                'reason' => $entry['reason'],
                'decided_by' => $entry['decided_by'],
                'decision_reference' => $entry['decision'],
                'buyer_or_beneficiary' => $entry['counterparty'] ?? null,
                'notes' => $entry['notes'],
            ], $asset->number);

            if ($entry['validated'] && $accountant instanceof User) {
                auth()->setUser($accountant);
                app(ValidateDisposal::class)($disposal, $accountant);
            }
        }

        $previous instanceof User ? auth()->setUser($previous) : auth()->forgetUser();
    }

    /**
     * @param  array{number?: string, category?: string, disposed_on: string}  $entry
     */
    private function asset(array $entry): ?Asset
    {
        $query = Asset::query()->withoutGlobalScopes()
            ->whereNull('disposed_at')
            ->whereIn('status', [AssetStatus::InService, AssetStatus::InStock, AssetStatus::OutOfService]);

        if (isset($entry['number'])) {
            return $query->where('number', $entry['number'])->first();
        }

        return $query
            ->whereNotIn('number', array_column(AssetsDemoSeeder::FIXED, 'number'))
            ->whereHas('category', fn ($category) => $category->where('prefix', $entry['category'] ?? ''))
            ->whereDate('acquired_on', '<=', $entry['disposed_on'])
            ->where(fn ($inService) => $inService->whereNull('in_service_on')->orWhereDate('in_service_on', '<=', $entry['disposed_on']))
            ->orderBy('number')
            ->first();
    }
}

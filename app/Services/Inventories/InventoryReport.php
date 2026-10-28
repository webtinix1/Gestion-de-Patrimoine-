<?php

namespace App\Services\Inventories;

use App\Enums\InventoryLineResult;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Compteurs (E20) et rapport par type d'écart (E21, RG-48, RG-49), limités aux lignes du périmètre
 * de l'utilisateur connecté (RG-81) : la page, le PV PDF et l'export Excel affichent les mêmes chiffres.
 */
class InventoryReport
{
    /** Sections du rapport, dans l'ordre de la maquette. */
    public const array SECTIONS = [
        'missing' => [InventoryLineResult::Missing, 'Biens manquants', 'danger'],
        'other_assignment' => [InventoryLineResult::OtherAssignment, 'Présents mais affectés ailleurs', 'warning'],
        'condition_mismatch' => [InventoryLineResult::ConditionMismatch, 'État différent', 'warning'],
        'unregistered' => [InventoryLineResult::Unregistered, 'Retrouvés mais non enregistrés', 'info'],
        'out_of_scope' => [InventoryLineResult::OutOfScope, 'Présents hors périmètre', 'info'],
        'justified' => [InventoryLineResult::Justified, 'Prêtés ou en maintenance au démarrage (justifiés)', 'gray'],
    ];

    /**
     * @return Builder<InventoryLine>
     */
    public function lines(Inventory $inventory): Builder
    {
        return InventoryLine::query()->where('inventory_id', $inventory->getKey());
    }

    /**
     * @return array{expected: int, present: int, missing: int, discrepancies: int, other_assignment: int, condition_mismatch: int, unregistered: int, out_of_scope: int, justified: int, pending: int, checked: int, progress: int}
     */
    public function counters(Inventory $inventory): array
    {
        $byResult = $this->lines($inventory)
            ->selectRaw('result, expected, count(*) as total')
            ->groupBy('result', 'expected')
            ->get();

        $count = fn (InventoryLineResult $result, ?bool $expected = null): int => (int) $byResult
            ->filter(fn (InventoryLine $row): bool => $row->result === $result && ($expected === null || (bool) $row->expected === $expected))
            ->sum('total');

        $expected = (int) $byResult->filter(fn (InventoryLine $row): bool => (bool) $row->expected)->sum('total');
        $pending = $count(InventoryLineResult::Pending, true);
        $other = $count(InventoryLineResult::OtherAssignment, true);
        $condition = $count(InventoryLineResult::ConditionMismatch, true);

        return [
            'expected' => $expected,
            'present' => $count(InventoryLineResult::Present, true) + $other + $condition,
            'missing' => $count(InventoryLineResult::Missing, true),
            'discrepancies' => $other + $condition,
            'other_assignment' => $other,
            'condition_mismatch' => $condition,
            'unregistered' => $count(InventoryLineResult::Unregistered),
            'out_of_scope' => $count(InventoryLineResult::OutOfScope),
            'justified' => $count(InventoryLineResult::Justified, true),
            'pending' => $pending,
            'checked' => $expected - $pending,
            'progress' => $expected === 0 ? 0 : (int) floor(($expected - $pending) * 100 / $expected),
        ];
    }

    /**
     * Nombre d'écarts (manquants, autre affectation, état, hors périmètre, non enregistrés).
     */
    public function discrepancyCount(Inventory $inventory): int
    {
        $counters = $this->counters($inventory);

        return $counters['missing'] + $counters['discrepancies'] + $counters['unregistered'] + $counters['out_of_scope'];
    }

    /**
     * @return array<string, array{label: string, color: string, lines: Collection<int, InventoryLine>}>
     */
    public function sections(Inventory $inventory): array
    {
        $lines = $this->lines($inventory)
            ->whereIn('result', array_map(fn (array $section): string => $section[0]->value, self::SECTIONS))
            ->with(['asset.department', 'asset.room', 'asset.holder', 'asset.condition', 'expectedRoom', 'expectedDepartment', 'expectedCondition',
                'foundRoom', 'foundDepartment', 'foundSite', 'foundCondition', 'checker', 'resolver'])
            ->orderBy('id')
            ->get();

        $sections = [];

        foreach (self::SECTIONS as $key => [$result, $label, $color]) {
            $sections[$key] = [
                'label' => $label,
                'color' => $color,
                'lines' => $lines->filter(fn (InventoryLine $line): bool => $line->result === $result)
                    ->sortBy(fn (InventoryLine $line): string => $line->asset->number ?? 'ZZZ'.$line->id)
                    ->values(),
            ];
        }

        return $sections;
    }

    /**
     * Observations saisies sur les lignes (maquette : « Observations des responsables »).
     *
     * @return Collection<int, InventoryLine>
     */
    public function observations(Inventory $inventory): Collection
    {
        return $this->lines($inventory)->whereNotNull('notes')->with(['asset', 'checker'])->orderBy('checked_at')->get();
    }

    /**
     * Tous les biens de la liste, pour la liste de contrôle et l'export Excel.
     *
     * @return Collection<int, InventoryLine>
     */
    public function all(Inventory $inventory): Collection
    {
        return $this->lines($inventory)
            ->with(['asset.category', 'expectedSite', 'expectedRoom', 'expectedDepartment', 'expectedCondition', 'foundRoom', 'foundDepartment', 'foundSite', 'foundCondition', 'checker'])
            ->get()
            ->sortBy(fn (InventoryLine $line): string => ($line->asset->number ?? 'ZZZ').str_pad((string) $line->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * RG-81 : mention des exports d'un utilisateur au périmètre limité.
     */
    public static function perimeterNote(User $user): ?string
    {
        $sites = $user->perimeterSiteIds();
        $departments = $user->perimeterDepartmentIds();

        if ($sites === null && $departments === null) {
            return null;
        }

        $parts = array_filter([
            $sites === null ? null : 'sites : '.Site::query()->withoutGlobalScopes()->whereKey($sites)->orderBy('name')->pluck('name')->join(', '),
            $departments === null ? null : 'départements : '.Department::query()->withoutGlobalScopes()->whereKey($departments)->orderBy('name')->pluck('name')->join(', '),
        ]);

        return 'Extrait limité au périmètre de '.$user->fullName().' ('.implode(' ; ', $parts).')';
    }

    public static function userName(?User $user): string
    {
        return $user?->fullName() ?? 'Application';
    }
}

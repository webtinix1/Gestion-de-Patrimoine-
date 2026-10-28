<?php

namespace App\Support\Reports;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Holder;
use App\Models\Service;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Exigence 15.3 : filtres communs des rapports (site, département, service, catégorie, statut, période,
 * responsable, exercice). Construits depuis des données venues du navigateur : tout est converti et
 * contrôlé ici (identifiants existants dans le périmètre, dates valides).
 */
final readonly class ReportFilters
{
    public function __construct(
        public ?int $siteId = null,
        public ?int $departmentId = null,
        public ?int $serviceId = null,
        public ?int $categoryId = null,
        public ?string $status = null,
        public ?int $holderId = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?int $year = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $id = fn (string $key, string $model): ?int => self::existing($model, $input[$key] ?? null);
        $date = function (string $key) use ($input): ?CarbonImmutable {
            $value = $input[$key] ?? null;

            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

            try {
                $parsed = CarbonImmutable::createFromFormat('Y-m-d', $value);
            } catch (\Throwable) {
                return null;
            }

            return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $value ? $parsed : null;
        };

        $year = $input['year'] ?? null;
        $status = $input['status'] ?? null;

        return new self(
            siteId: $id('site_id', Site::class),
            departmentId: $id('department_id', Department::class),
            serviceId: $id('service_id', Service::class),
            categoryId: $id('category_id', AssetCategory::class),
            status: is_string($status) && preg_match('/^[a-z_]{1,30}$/', $status) ? $status : null,
            holderId: $id('holder_id', Holder::class),
            from: $date('from'),
            to: $date('to'),
            year: is_numeric($year) && (int) $year >= 2000 && (int) $year <= 2100 ? (int) $year : null,
        );
    }

    /**
     * Ne garde que les filtres que le rapport accepte (exigence 15.3) ; le statut doit être l'un de ses statuts.
     *
     * @param  array<string, mixed>  $input
     */
    public static function forReport(ReportDefinition $report, array $input): self
    {
        $keys = [
            'site' => ['site_id'], 'department' => ['department_id'], 'service' => ['service_id'], 'category' => ['category_id'],
            'status' => ['status'], 'holder' => ['holder_id'], 'period' => ['from', 'to'], 'year' => ['year'],
        ];

        $allowed = [];

        foreach ($report->filters as $filter) {
            foreach ($keys[$filter] ?? [] as $key) {
                $allowed[$key] = $input[$key] ?? null;
            }
        }

        if (isset($allowed['status']) && ! array_key_exists((string) $allowed['status'], $report->statusOptions())) {
            $allowed['status'] = null;
        }

        return self::fromArray($allowed);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function existing(string $model, mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        // Le périmètre de l'utilisateur s'applique : un identifiant hors périmètre est ignoré.
        // Les responsables suivent en plus le périmètre (départements de l'utilisateur ou biens visibles).
        $exists = $model === Holder::class
            ? Holder::query()->inPerimeter()->whereKey((int) $value)->exists()
            : $model::query()->whereKey((int) $value)->exists();

        return $exists ? (int) $value : null;
    }

    /**
     * Filtres actifs en clair, pour l'en-tête des PDF et l'aperçu.
     *
     * @return list<string>
     */
    public function labels(?string $statusLabel = null): array
    {
        $labels = [];

        $lookup = [
            ['Site', $this->siteId, Site::class],
            ['Département', $this->departmentId, Department::class],
            ['Service', $this->serviceId, Service::class],
            ['Catégorie', $this->categoryId, AssetCategory::class],
            ['Responsable', $this->holderId, Holder::class],
        ];

        foreach ($lookup as [$label, $id, $model]) {
            if ($id !== null) {
                $labels[] = $label.' : '.($model::query()->find($id)->name ?? '—');
            }
        }

        if ($this->status !== null) {
            $labels[] = 'Statut : '.($statusLabel ?? $this->status);
        }

        if ($this->from !== null || $this->to !== null) {
            $labels[] = 'Période : '.($this->from?->format('d/m/Y') ?? '…').' au '.($this->to?->format('d/m/Y') ?? '…');
        }

        if ($this->year !== null) {
            $labels[] = 'Exercice : '.$this->year;
        }

        return $labels;
    }

    /**
     * Bornes de la période : dates saisies, sinon l'exercice choisi (année civile).
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function range(): array
    {
        if ($this->from === null && $this->to === null && $this->year !== null) {
            return [CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31)];
        }

        return [$this->from, $this->to];
    }

    /**
     * Filtres portant sur le bien (colonnes de la table assets), sans la période.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function applyToAssets(Builder $query, bool $withPeriod = false, bool $withStatus = true): Builder
    {
        $query
            ->when($this->siteId, fn (Builder $q, int $id) => $q->where('assets.site_id', $id))
            ->when($this->departmentId, fn (Builder $q, int $id) => $q->where('assets.department_id', $id))
            ->when($this->serviceId, fn (Builder $q, int $id) => $q->where('assets.service_id', $id))
            ->when($this->categoryId, fn (Builder $q, int $id) => $q->where('assets.asset_category_id', $id))
            ->when($this->holderId, fn (Builder $q, int $id) => $q->where('assets.holder_id', $id));

        if ($withStatus && $this->status !== null) {
            $query->where('assets.status', $this->status);
        }

        if ($withPeriod) {
            [$from, $to] = $this->range();
            $query
                ->when($from, fn (Builder $q, CarbonImmutable $date) => $q->where('assets.acquired_on', '>=', $date->toDateString()))
                ->when($to, fn (Builder $q, CarbonImmutable $date) => $q->where('assets.acquired_on', '<=', $date->toDateString()));
        }

        return $query;
    }
}

<?php

namespace App\Services;

use App\Enums\FundingSource;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contrôle et normalise la saisie d'une acquisition (RG-15) : les totaux sont toujours calculés,
 * jamais repris de la saisie. RG-81 : site et département restent dans le périmètre de l'utilisateur.
 */
class AcquisitionRules
{
    public const int MAX_QUANTITY = 500;

    public const int MAX_PRICE = 999_999_999_999;

    // Le verrou du compteur est tenu pendant toute la validation, et les identifiants passent dans l'URL des étiquettes.
    public const int MAX_TRACKED_UNITS = 500;

    /**
     * @param  iterable<mixed>  $lines
     */
    public static function trackedUnits(iterable $lines): int
    {
        $units = 0;

        foreach ($lines as $line) {
            if (! empty($line['tracked_individually'])) {
                $units += (int) $line['quantity'];
            }
        }

        return $units;
    }

    public static function tooManyUnitsMessage(): string
    {
        return 'Une acquisition ne peut pas créer plus de '.self::MAX_TRACKED_UNITS.' fiches : répartissez les lignes sur plusieurs acquisitions.';
    }

    public static function lineTotal(int $quantity, int $unitPrice): int
    {
        return $quantity * $unitPrice;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function normalize(array $data): array
    {
        try {
            $validated = Validator::make($data, [
                'acquired_on' => ['required', 'date', 'before_or_equal:today'],
                'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('is_active', true)],
                'invoice_reference' => ['nullable', 'string', 'max:255'],
                'funding_source' => ['nullable', Rule::enum(FundingSource::class)],
                'site_id' => ['required', Rule::exists('sites', 'id')],
                'department_id' => ['required', Rule::exists('departments', 'id')],
                'lines' => ['required', 'array', 'min:1'],
                'lines.*.designation' => ['required', 'string', 'max:255'],
                'lines.*.asset_category_id' => ['required', Rule::exists('asset_categories', 'id')->where('is_active', true)],
                'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
                'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
                'lines.*.tracked_individually' => ['nullable', 'boolean'],
            ], [], [
                'lines' => 'lignes de la facture',
                'lines.*.designation' => 'désignation',
                'lines.*.asset_category_id' => 'catégorie',
                'lines.*.quantity' => 'quantité',
                'lines.*.unit_price' => 'prix unitaire',
            ])->validate();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all(),
            );
        }

        $this->assertInPerimeter((int) $validated['site_id'], (int) $validated['department_id']);

        if (self::trackedUnits($validated['lines']) > self::MAX_TRACKED_UNITS) {
            throw ValidationException::withMessages(['data.lines' => self::tooManyUnitsMessage()]);
        }

        $lines = array_values(array_map(function (array $line): array {
            $quantity = (int) $line['quantity'];
            $unitPrice = (int) $line['unit_price'];

            return [
                'designation' => trim((string) $line['designation']),
                'asset_category_id' => (int) $line['asset_category_id'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tracked_individually' => (bool) ($line['tracked_individually'] ?? false),
                'line_total' => self::lineTotal($quantity, $unitPrice),
            ];
        }, $validated['lines']));

        return [
            'header' => [
                'acquired_on' => $validated['acquired_on'],
                'supplier_id' => (int) $validated['supplier_id'],
                'invoice_reference' => $validated['invoice_reference'] ?? null,
                'funding_source' => $validated['funding_source'] ?? null,
                'site_id' => (int) $validated['site_id'],
                'department_id' => (int) $validated['department_id'],
                'total_amount' => array_sum(array_column($lines, 'line_total')),
            ],
            'lines' => $lines,
        ];
    }

    private function assertInPerimeter(int $siteId, int $departmentId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $sites = $user->perimeterSiteIds();
        $departments = $user->perimeterDepartmentIds();

        if ($sites !== null && ! in_array($siteId, $sites, true)) {
            throw ValidationException::withMessages(['data.site_id' => 'Ce site est hors de votre périmètre.']);
        }

        if ($departments !== null && ! in_array($departmentId, $departments, true)) {
            throw ValidationException::withMessages(['data.department_id' => 'Ce département est hors de votre périmètre.']);
        }
    }
}

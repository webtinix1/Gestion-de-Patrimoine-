<?php

namespace App\Actions\Depreciation;

use App\Enums\DepreciationMethod;
use App\Enums\PermissionAbility;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\DepreciationLine;
use App\Models\DepreciationParameterChange;
use App\Models\DepreciationSchedule;
use App\Models\User;
use App\Services\DepreciabilityResolver;
use App\Services\DepreciationRules;
use App\Services\FiscalYearCalendar;
use App\Support\DepreciationAuthority;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * RG-37 : modification d'un paramètre d'amortissement (E31, « Modifier les paramètres »).
 * Motif obligatoire, historique (ancienne et nouvelle valeur, date, utilisateur, motif), recalcul
 * des seuls exercices ouverts : la VNC au début du premier exercice ouvert est répartie sur la
 * durée restante recalculée (cas A6).
 */
class ChangeDepreciationParameters
{
    /**
     * @var list<string>
     */
    public const array PARAMETERS = ['acquisition_cost', 'residual_value', 'duration_years', 'in_service_on', 'depreciation_method'];

    public function __construct(
        private SyncDepreciationSchedule $sync,
        private DepreciationRules $rules,
        private DepreciabilityResolver $depreciability,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  nouvelles valeurs (parmi PARAMETERS)
     * @param  int|null  $expectedVersion  RG-86 : version de la fiche lue à l'ouverture
     */
    public function __invoke(Asset $asset, array $changes, string $reason, ?int $expectedVersion = null): ?DepreciationSchedule
    {
        $user = auth()->user();

        // RG-83 : règle appliquée aussi dans l'action, en plus de la policy de l'écran.
        if (! $user instanceof User || ! DepreciationAuthority::canManage($user, PermissionAbility::Update)) {
            throw new AuthorizationException('Seuls l\'administrateur et le comptable modifient les paramètres d\'amortissement (RG-83).');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Le motif de la modification est obligatoire (RG-37).']);
        }

        $calendar = new FiscalYearCalendar;

        return DB::transaction(function () use ($asset, $changes, $reason, $expectedVersion, $calendar): ?DepreciationSchedule {
            $current = Asset::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($asset->getKey());

            if ($expectedVersion !== null && $current->lock_version !== $expectedVersion) {
                throw new ConcurrentModificationException(
                    $current->updated_by ? User::query()->find($current->updated_by) : null,
                    $current->updated_at,
                );
            }

            if ($current->isDisposed() || ! $current->is_depreciable) {
                throw ValidationException::withMessages([
                    'reason' => 'Seul un bien amortissable encore au patrimoine a des paramètres d\'amortissement modifiables.',
                ]);
            }

            $values = $this->normalize($current, $changes, $calendar);
            $old = [];
            $new = [];

            foreach ($values as $parameter => $value) {
                $before = self::stringValue($current, $parameter);

                if ($before !== self::stringOf($value)) {
                    $old[$parameter] = $before;
                    $new[$parameter] = self::stringOf($value);
                }
            }

            if ($new === []) {
                throw ValidationException::withMessages(['reason' => 'Aucun paramètre n\'a changé.']);
            }

            $current->forceFill($values)->save();

            $anchor = $calendar->firstRecalculableYear();
            ($this->sync)($current, $anchor, $calendar);

            $batch = (string) Str::uuid();
            $effective = $anchor === null ? null : $calendar->find($anchor);

            foreach ($new as $parameter => $value) {
                DepreciationParameterChange::query()->create([
                    'asset_id' => $current->getKey(),
                    'batch_uuid' => $batch,
                    'parameter' => $parameter,
                    'old_value' => $old[$parameter],
                    'new_value' => $value,
                    'reason' => $reason,
                    'effective_year' => $anchor,
                    'effective_fiscal_year_id' => $effective?->getKey(),
                    'changed_by' => auth()->id(),
                    'changed_at' => now(),
                ]);
            }

            // RG-70 : modification d'un paramètre d'amortissement (avant, après, motif).
            activity('amortissements')
                ->performedOn($current)
                ->event('depreciation_parameters_changed')
                ->withProperties(['old' => $old, 'attributes' => $new, 'reason' => $reason, 'effective_year' => $anchor])
                ->log('Modification d\'un paramètre d\'amortissement');

            $asset->setRawAttributes($current->getAttributes(), sync: true);

            return app(SyncDepreciationSchedule::class)->currentSchedule($current);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function normalize(Asset $asset, array $changes, FiscalYearCalendar $calendar): array
    {
        $errors = [];
        $has = fn (string $parameter): bool => array_key_exists($parameter, $changes);

        $cost = $has('acquisition_cost') ? self::integer($changes['acquisition_cost']) : (int) $asset->acquisition_cost;
        $residual = $has('residual_value') ? self::integer($changes['residual_value']) : $asset->residual_value;
        $duration = $has('duration_years') ? self::integer($changes['duration_years']) : (int) $asset->duration_years;
        $inService = $has('in_service_on') ? $this->date($changes['in_service_on']) : $asset->in_service_on?->toDateString();
        $method = $has('depreciation_method') ? self::method($changes['depreciation_method']) : $asset->depreciation_method;

        if ($cost === null || $cost <= 0) {
            $errors['acquisition_cost'] = 'La valeur d\'origine doit être un montant positif.';
        }

        if ($residual === null || $residual < 0 || $residual >= (int) $cost) {
            $errors['residual_value'] = 'La valeur résiduelle doit être positive et inférieure à la valeur d\'origine.';
        }

        if ($duration === null || $duration < 1 || $duration > 100) {
            $errors['duration_years'] = 'La durée doit être comprise entre 1 et 100 ans.';
        }

        if ($inService === null) {
            $errors['in_service_on'] = 'La date de mise en service est obligatoire.';
        } elseif ($inService < $asset->acquired_on->toDateString()) {
            $errors['in_service_on'] = 'La mise en service ne peut pas précéder l\'acquisition.';
        } elseif ($inService !== $asset->in_service_on?->toDateString()) {
            $message = $this->frozenStartError($asset, (int) substr($inService, 0, 4), $calendar);

            if ($message !== null) {
                $errors['in_service_on'] = $message;
            }
        }

        if ($method === null) {
            $errors['depreciation_method'] = 'Méthode inconnue.';
        } elseif ($method === DepreciationMethod::Declining) {
            if (! $this->rules->decliningEnabled() && $asset->depreciation_method !== DepreciationMethod::Declining) {
                $errors['depreciation_method'] = 'La méthode dégressive n\'est pas activée dans les règles d\'amortissement (RG-35).';
            } elseif ($duration !== null && $this->rules->coefficientFor($duration) === null) {
                $errors['depreciation_method'] = 'La méthode dégressive s\'applique à une durée d\'au moins 3 ans (RG-35).';
            }
        }

        $category = AssetCategory::query()->find($asset->asset_category_id);

        if ($category !== null && $cost !== null && ! isset($errors['acquisition_cost'])
            && ! $this->depreciability->isDepreciable($category, $cost, $asset->depreciation_forced_off)) {
            $errors['acquisition_cost'] = 'Avec cette valeur, le bien ne serait plus amortissable (seuil d\'immobilisation, RG-30).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $values = array_intersect_key([
            'acquisition_cost' => $cost,
            'residual_value' => $residual,
            'duration_years' => $duration,
            'in_service_on' => $inService,
            'depreciation_method' => $method,
        ], array_flip(array_keys(array_intersect_key($changes, array_flip(self::PARAMETERS)))));

        // RG-35 : le coefficient dépend de la durée ; il est fixé sur la fiche quand la méthode ou la
        // durée change, puis ne bouge plus si les paramètres généraux changent.
        if ($method === DepreciationMethod::Declining && $duration !== null
            && ($method !== $asset->depreciation_method || $duration !== (int) $asset->duration_years || $asset->getAttribute('declining_coefficient') === null)) {
            $values['declining_coefficient'] = $this->rules->coefficientFor($duration);
        } elseif ($method !== DepreciationMethod::Declining && $asset->getAttribute('declining_coefficient') !== null) {
            $values['declining_coefficient'] = null;
        }

        return $values;
    }

    /**
     * RG-36 : une nouvelle date de mise en service ne doit ni effacer une dotation figée ni en
     * créer une dans un exercice clôturé ou antérieur.
     */
    private function frozenStartError(Asset $asset, int $startYear, FiscalYearCalendar $calendar): ?string
    {
        $first = $calendar->firstRecalculableYear();
        $schedule = $this->sync->currentSchedule($asset);

        // Sans plan existant, la date se traite comme à la création (Q9.1).
        if ($first === null || $schedule === null) {
            return null;
        }

        $fixed = $schedule->lines
            ->filter(fn (DepreciationLine $line): bool => $line->is_locked || $line->fiscal_year < $first);
        $withAmount = $fixed->filter(fn (DepreciationLine $line): bool => $line->amount > 0);

        if ($withAmount->isNotEmpty() && $startYear > (int) $withAmount->min('fiscal_year')) {
            return 'Le bien porte déjà une dotation figée en '.$withAmount->min('fiscal_year').' (exercice clôturé) : sa mise en service ne peut pas être repoussée après cet exercice.';
        }

        for ($year = $startYear; $year < $first; $year++) {
            if (! $fixed->contains(fn (DepreciationLine $line): bool => $line->fiscal_year === $year)) {
                return 'Avec cette date, une dotation serait ajoutée à l\'exercice '.$year.', déjà clôturé ou antérieur : c\'est interdit (RG-36).';
            }
        }

        return null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function method(mixed $value): ?DepreciationMethod
    {
        return $value instanceof DepreciationMethod ? $value : DepreciationMethod::tryFrom(is_string($value) ? $value : '');
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private static function stringValue(Asset $asset, string $parameter): ?string
    {
        return match ($parameter) {
            'in_service_on' => $asset->in_service_on?->toDateString(),
            default => self::stringOf($asset->getAttribute($parameter)),
        };
    }

    private static function stringOf(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DepreciationMethod => $value->value,
            is_scalar($value) => (string) $value,
            default => null,
        };
    }
}

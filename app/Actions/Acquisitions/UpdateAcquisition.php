<?php

namespace App\Actions\Acquisitions;

use App\Exceptions\ConcurrentModificationException;
use App\Models\Acquisition;
use App\Models\User;
use App\Services\AcquisitionRules;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modifie un brouillon et remplace ses lignes. RG-15 : une acquisition validée ne se modifie plus.
 * RG-86 : la version lue à l'ouverture du formulaire est comparée sous verrou. RG-70 : avant / après lisibles.
 */
class UpdateAcquisition
{
    public function __construct(private AcquisitionRules $rules) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $expectedVersion  version du brouillon lue à l'ouverture du formulaire
     */
    public function __invoke(Acquisition $acquisition, array $data, ?int $expectedVersion = null): Acquisition
    {
        $input = $this->rules->normalize($data);

        return DB::transaction(function () use ($acquisition, $input, $expectedVersion): Acquisition {
            $locked = Acquisition::query()->whereKey($acquisition->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException('Une acquisition validée n\'est plus modifiable.');
            }

            if ($expectedVersion !== null && $locked->lock_version !== $expectedVersion) {
                throw new ConcurrentModificationException(
                    $locked->updated_by ? User::query()->find($locked->updated_by) : null,
                    $locked->updated_at,
                );
            }

            // La référence porte l'année de la date d'acquisition : cette année ne change plus après la création.
            if (Carbon::parse($input['header']['acquired_on'])->year !== $locked->acquired_on->year) {
                throw ValidationException::withMessages([
                    'data.acquired_on' => 'La date d\'acquisition doit rester dans l\'année '.$locked->acquired_on->year.' : elle figure dans la référence '.$locked->reference.'.',
                ]);
            }

            $before = $this->snapshot($locked);

            $locked->fill($input['header']);
            // Le journal reçoit une seule entrée lisible, écrite plus bas.
            $locked->disableLogging();
            $locked->forceFill(['lock_version' => $locked->lock_version + 1])->save();
            $locked->enableLogging();
            $locked->lines()->delete();
            $locked->lines()->createMany($input['lines']);
            $locked->unsetRelations();

            $after = $this->snapshot($locked);

            if ($before !== $after) {
                activity('acquisitions')
                    ->performedOn($locked)
                    ->event('updated')
                    ->withProperties(['old' => $before, 'attributes' => $after])
                    ->log('Modification d\'une acquisition');
            }

            return $locked;
        }, attempts: 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Acquisition $acquisition): array
    {
        $acquisition->load(['supplier', 'site', 'department', 'lines.category']);

        return [
            'date d\'acquisition' => $acquisition->acquired_on->format('d/m/Y'),
            'fournisseur' => $acquisition->supplier?->name,
            'facture' => $acquisition->invoice_reference,
            'financement' => $acquisition->funding_source?->getLabel(),
            'site' => $acquisition->site->name,
            'département' => $acquisition->department->name,
            'montant total (FCFA)' => Money::format($acquisition->total_amount),
            'lignes' => $acquisition->lines->map(fn ($line): string => sprintf(
                '%s · %s · %d × %s FCFA · %s',
                $line->designation,
                $line->category->name,
                $line->quantity,
                Money::format($line->unit_price),
                $line->tracked_individually ? 'suivi individuel' : 'non suivi',
            ))->all(),
        ];
    }
}

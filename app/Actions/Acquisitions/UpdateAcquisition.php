<?php

namespace App\Actions\Acquisitions;

use App\Models\Acquisition;
use App\Services\AcquisitionRules;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Modifie un brouillon et remplace ses lignes. RG-15 : une acquisition validée ne se modifie plus.
 */
class UpdateAcquisition
{
    public function __construct(private AcquisitionRules $rules) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Acquisition $acquisition, array $data): Acquisition
    {
        $input = $this->rules->normalize($data);

        return DB::transaction(function () use ($acquisition, $input): Acquisition {
            $locked = Acquisition::query()->whereKey($acquisition->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException('Une acquisition validée n\'est plus modifiable.');
            }

            $locked->update($input['header']);
            $locked->lines()->delete();
            $locked->lines()->createMany($input['lines']);

            return $locked;
        }, attempts: 5);
    }
}

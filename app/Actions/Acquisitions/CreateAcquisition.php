<?php

namespace App\Actions\Acquisitions;

use App\Models\Acquisition;
use App\Services\AcquisitionReferenceGenerator;
use App\Services\AcquisitionRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre une acquisition en brouillon avec ses lignes et sa référence (RG-15, RG-70).
 */
class CreateAcquisition
{
    public function __construct(
        private AcquisitionRules $rules,
        private AcquisitionReferenceGenerator $references,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Acquisition
    {
        $input = $this->rules->normalize($data);

        return DB::transaction(function () use ($input): Acquisition {
            $acquisition = new Acquisition($input['header']);
            $acquisition->forceFill(['reference' => $this->references->next(Carbon::parse($input['header']['acquired_on'])->year)]);
            $acquisition->save();

            $acquisition->lines()->createMany($input['lines']);

            return $acquisition;
        }, attempts: 5);
    }
}

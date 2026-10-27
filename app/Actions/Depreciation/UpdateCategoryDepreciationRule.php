<?php

namespace App\Actions\Depreciation;

use App\Enums\DepreciationMethod;
use App\Models\AssetCategory;
use App\Services\DepreciationRules;
use Illuminate\Validation\ValidationException;

/**
 * E32 : règle d'amortissement d'une catégorie (RG-31). Valeurs proposées aux nouveaux biens :
 * une modification ne recalcule pas les biens déjà enregistrés.
 */
class UpdateCategoryDepreciationRule
{
    /**
     * @var list<string>
     */
    public const array FIELDS = ['default_method', 'default_duration_years', 'asset_account', 'depreciation_account', 'expense_account'];

    public function __construct(private DepreciationRules $rules) {}

    public function __invoke(AssetCategory $category, string $field, mixed $value): AssetCategory
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw ValidationException::withMessages([$field => 'Champ non modifiable ici.']);
        }

        $value = match ($field) {
            'default_method' => $this->method($value),
            'default_duration_years' => $this->duration($value),
            default => $this->account($field, $value),
        };

        $before = $category->getAttribute($field);
        $category->setAttribute($field, $value);

        if (! $category->isDirty($field)) {
            return $category;
        }

        $category->save();

        activity('amortissements')
            ->performedOn($category)
            ->event('category_rule_updated')
            ->withProperties([
                'old' => [$field => $before instanceof DepreciationMethod ? $before->value : $before],
                'attributes' => [$field => $value instanceof DepreciationMethod ? $value->value : $value],
            ])
            ->log('Modification de la règle d\'amortissement « '.$category->name.' »');

        return $category;
    }

    private function method(mixed $value): DepreciationMethod
    {
        $method = $value instanceof DepreciationMethod ? $value : DepreciationMethod::tryFrom((string) $value);

        if ($method === null) {
            throw ValidationException::withMessages(['default_method' => 'Méthode inconnue.']);
        }

        if ($method === DepreciationMethod::Declining && ! $this->rules->decliningEnabled()) {
            throw ValidationException::withMessages(['default_method' => 'La méthode dégressive n\'est pas activée (RG-35).']);
        }

        return $method;
    }

    private function duration(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 1 || (int) $value > 100 || (int) $value != $value) {
            throw ValidationException::withMessages(['default_duration_years' => 'La durée doit être un nombre entier d\'années entre 1 et 100.']);
        }

        return (int) $value;
    }

    private function account(string $field, mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value !== '' && preg_match('/^\d{2,20}$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Un numéro de compte ne contient que des chiffres.']);
        }

        return $value === '' ? null : $value;
    }
}

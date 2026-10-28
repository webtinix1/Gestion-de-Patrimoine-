<?php

namespace App\Support\Reports;

use App\Enums\PermissionModule;

/**
 * Un rapport du catalogue E29 : sa clé, son groupe, le module dont il dépend (droits) et les filtres qui
 * s'y appliquent (exigence 15.3).
 */
final readonly class ReportDefinition
{
    /**
     * @param  list<string>  $filters  site, department, service, category, status, holder, period, year
     * @param  class-string<\BackedEnum>|null  $statusEnum
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public PermissionModule $module,
        public array $filters,
        public ?string $statusEnum = null,
        public bool $usesFiscalYear = false,
    ) {}

    public function supports(string $filter): bool
    {
        return in_array($filter, $this->filters, true);
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        if ($this->statusEnum === null) {
            return [];
        }

        $options = [];

        foreach ($this->statusEnum::cases() as $case) {
            $options[(string) $case->value] = method_exists($case, 'getLabel') ? (string) $case->getLabel() : (string) $case->value;
        }

        return $options;
    }
}

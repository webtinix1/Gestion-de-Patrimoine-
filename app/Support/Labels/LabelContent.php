<?php

namespace App\Support\Labels;

use App\Models\Asset;

/**
 * Ce qu'une étiquette peut afficher d'un bien. Le code, lui, ne contient que le numéro (RG-95).
 */
final readonly class LabelContent
{
    public function __construct(
        public string $number,
        public ?string $designation = null,
        public ?string $department = null,
        public ?string $site = null,
        public ?string $room = null,
    ) {}

    public static function fromAsset(Asset $asset): self
    {
        return new self(
            number: $asset->number,
            designation: $asset->label(),
            department: $asset->department->name ?? null,
            site: $asset->site->name ?? null,
            room: $asset->room?->name,
        );
    }
}

<?php

use App\Enums\Depreciability;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\Supplier;
use Database\Seeders\ReferenceSeeder;

it('charge les états par défaut dans l\'ordre (RG-11)', function () {
    $this->seed(ReferenceSeeder::class);

    expect(AssetCondition::query()->orderBy('sort')->pluck('label')->all())
        ->toBe(['Neuf', 'Très bon état', 'Bon état', 'État moyen', 'À réparer', 'Hors service']);
});

it('charge les dix catégories par défaut avec leurs préfixes', function () {
    $this->seed(ReferenceSeeder::class);

    expect(AssetCategory::query()->orderBy('prefix')->pluck('prefix')->all())
        ->toBe(['AUD', 'AUT', 'BUR', 'CUI', 'CUL', 'ELE', 'INF', 'MOB', 'SON', 'TRA'])
        ->and(AssetCategory::query()->where('prefix', 'INF')->sole())
        ->default_duration_years->toBe(3)
        ->asset_account->toBe('2441')
        ->and(AssetCategory::query()->where('prefix', 'AUT')->sole()->depreciable)->toBe(Depreciability::No)
        ->and(DocumentType::query()->count())->toBe(10);
});

it('n\'écrase pas une valeur de référence modifiée par le client', function () {
    $this->seed(ReferenceSeeder::class);
    AssetCategory::query()->where('prefix', 'MOB')->update(['default_duration_years' => 12]);

    $this->seed(ReferenceSeeder::class);

    expect(AssetCategory::query()->where('prefix', 'MOB')->sole()->default_duration_years)->toBe(12)
        ->and(AssetCategory::query()->count())->toBe(10);
});

it('charge la structure de démonstration de la maquette', function () {
    $this->seed();

    expect(Site::query()->count())->toBe(5)
        ->and(Site::query()->active()->count())->toBe(4)
        ->and(Department::query()->count())->toBe(9)
        ->and(Service::query()->count())->toBe(22)
        ->and(Room::query()->count())->toBe(16)
        ->and(Supplier::query()->count())->toBe(7)
        ->and(Department::query()->where('code', 'ADM')->sole()->sites()->count())->toBe(4)
        ->and(Department::query()->where('code', 'FIN')->sole()->sites()->pluck('code')->all())->toBe(['SIE']);
});

it('peut être rejoué sans doublon', function () {
    $this->seed();
    $this->seed();

    expect(Site::query()->count())->toBe(5)
        ->and(Service::query()->count())->toBe(22)
        ->and(Room::query()->count())->toBe(16);
});

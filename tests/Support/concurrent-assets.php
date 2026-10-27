<?php

/*
 * Processus lancé en parallèle par NumeroPatrimonialConcurrenceTest (RG-03).
 * Usage : php tests/Support/concurrent-assets.php setup|numbers|assets <nombre>
 */

use App\Actions\Assets\CreateAsset;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Site;
use App\Services\AssetNumberGenerator;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'numbers';
$count = (int) ($argv[2] ?? 10);

if ($mode === 'setup') {
    AssetCategory::query()->create(['name' => 'Informatique', 'prefix' => 'INF', 'depreciable' => Depreciability::No]);
    Site::query()->create(['name' => 'Siège', 'code' => 'SIE']);
    Department::query()->create(['name' => 'Administration', 'code' => 'ADM']);
    AssetCondition::query()->create(['label' => 'Bon état', 'sort' => 1]);

    exit(0);
}

$category = AssetCategory::query()->firstOrFail();

for ($i = 0; $i < $count; $i++) {
    if ($mode === 'numbers') {
        echo app(AssetNumberGenerator::class)->next($category).PHP_EOL;

        continue;
    }

    $asset = app(CreateAsset::class)([
        'designation' => 'Chaise '.getmypid().'-'.$i,
        'asset_category_id' => $category->id,
        'acquired_on' => '2026-10-27',
        'site_id' => Site::query()->value('id'),
        'department_id' => Department::query()->value('id'),
        'asset_condition_id' => AssetCondition::query()->value('id'),
        'status' => AssetStatus::InService->value,
    ]);

    echo $asset->number.PHP_EOL;
}

<?php

/*
 * Processus lancé en parallèle par ValidationAcquisitionConcurrenceTest (RG-15).
 * Usage : php tests/Support/concurrent-acquisitions.php setup|draft|validate|assets|report <argument>
 */

use App\Actions\Acquisitions\CreateAcquisition;
use App\Actions\Acquisitions\ValidateAcquisition;
use App\Actions\Assets\CreateAsset;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Models\Acquisition;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Site;
use App\Models\Supplier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'report';
$arg = (int) ($argv[2] ?? 1);

switch ($mode) {
    case 'setup':
        AssetCategory::query()->create(['name' => 'Informatique', 'prefix' => 'INF', 'depreciable' => Depreciability::No]);
        Site::query()->create(['name' => 'Siège', 'code' => 'SIE']);
        Department::query()->create(['name' => 'Administration', 'code' => 'ADM']);
        AssetCondition::query()->create(['label' => 'Neuf', 'sort' => 1]);
        Supplier::query()->create(['name' => 'Fournisseur']);
        break;

    case 'draft': // crée un brouillon de $arg unités suivies et affiche son identifiant
        echo app(CreateAcquisition::class)([
            'acquired_on' => '2026-10-20',
            'supplier_id' => Supplier::query()->value('id'),
            'site_id' => Site::query()->value('id'),
            'department_id' => Department::query()->value('id'),
            'lines' => [['designation' => 'Portable', 'asset_category_id' => AssetCategory::query()->value('id'), 'quantity' => $arg, 'unit_price' => 1000, 'tracked_individually' => true]],
        ])->id.PHP_EOL;
        break;

    case 'validate': // modèle chargé avant, comme un écran resté ouvert
        $acquisition = Acquisition::query()->findOrFail($arg);
        usleep(random_int(0, 2000));

        try {
            $assets = app(ValidateAcquisition::class)($acquisition);
            echo 'OK '.$assets->pluck('number')->implode(',').PHP_EOL;
        } catch (DomainException) {
            echo 'REFUS'.PHP_EOL;
        }
        break;

    case 'assets': // $arg biens créés à l'unité par ailleurs
        for ($i = 0; $i < $arg; $i++) {
            echo 'SOLO '.app(CreateAsset::class)([
                'designation' => 'Chaise '.getmypid().'-'.$i,
                'asset_category_id' => AssetCategory::query()->value('id'),
                'acquired_on' => '2026-10-27',
                'site_id' => Site::query()->value('id'),
                'department_id' => Department::query()->value('id'),
                'asset_condition_id' => AssetCondition::query()->value('id'),
                'status' => AssetStatus::InService->value,
            ])->number.PHP_EOL;
        }
        break;

    case 'report':
        foreach (DB::table('assets')->orderBy('number')->get(['number', 'acquisition_line_id']) as $row) {
            echo $row->number.' '.($row->acquisition_line_id ?? '-').PHP_EOL;
        }
        break;
}

<?php

/*
 * Processus lancé en parallèle par SortieConcurrenceTest (RG-50, RG-53).
 * Usage : php tests/Support/concurrent-disposals.php setup|dispose|report <argument>
 */

use App\Actions\Assets\CreateAsset;
use App\Actions\Disposals\RecordDisposal;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Enums\FiscalYearStatus;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FiscalYear;
use App\Models\Site;
use App\Services\DisposalReferenceGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'report';
$arg = (int) ($argv[2] ?? 1);

switch ($mode) {
    case 'setup': // $arg biens amortissables, exercice 2025 clôturé et 2026 ouvert
        foreach ([2025 => FiscalYearStatus::Closed, 2026 => FiscalYearStatus::Open] as $year => $status) {
            FiscalYear::query()->create(['year' => $year, 'label' => (string) $year, 'starts_on' => $year.'-01-01', 'ends_on' => $year.'-12-31', 'status' => $status]);
        }

        $category = AssetCategory::query()->create(['name' => 'Électrique', 'prefix' => 'ELE', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 8]);
        $site = Site::query()->create(['name' => 'Siège', 'code' => 'SIE']);
        $department = Department::query()->create(['name' => 'Logistique', 'code' => 'LOG']);
        $condition = AssetCondition::query()->create(['label' => 'Hors service', 'sort' => 1]);

        for ($i = 0; $i < $arg; $i++) {
            app(CreateAsset::class)([
                'designation' => 'Groupe électrogène '.$i,
                'asset_category_id' => $category->id,
                'acquired_on' => '2022-06-15',
                'in_service_on' => '2022-06-15',
                'acquisition_cost' => 4800000,
                'site_id' => $site->id,
                'department_id' => $department->id,
                'asset_condition_id' => $condition->id,
                'status' => AssetStatus::InService->value,
            ]);
        }
        break;

    case 'dispose': // modèle chargé avant, comme un écran resté ouvert
        $asset = Asset::query()->orderBy('id')->skip($arg - 1)->firstOrFail();
        usleep(random_int(0, 2000));

        try {
            $disposal = app(RecordDisposal::class)($asset, [
                'disposed_on' => '2026-10-26',
                'reason' => 'retirement',
                'decided_by' => 'Conseil d\'administration',
            ], $asset->number, $asset->lock_version);
            echo 'OK '.$disposal->reference.' '.$asset->number.PHP_EOL;
        } catch (ValidationException|ConcurrentModificationException) {
            echo 'REFUS'.PHP_EOL;
        }
        break;

    case 'retry': // interblocage simulé à la 1re tentative, avec un justificatif (disque dans DISK_ROOT)
        config(['filesystems.disks.local.root' => (string) getenv('DISK_ROOT')]);
        Storage::forgetDisk('local');
        $app->bind(DisposalReferenceGenerator::class, fn () => new class extends DisposalReferenceGenerator
        {
            public static int $calls = 0;

            public function next(int $year): string
            {
                if (++self::$calls === 1) {
                    throw new DeadlockException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction');
                }

                return parent::next($year);
            }
        });

        $asset = Asset::query()->orderBy('id')->firstOrFail();
        $type = DocumentType::query()->firstOrCreate(['label' => 'Document de réforme']);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pv');
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $disposal = app(RecordDisposal::class)($asset, ['disposed_on' => '2026-10-26', 'reason' => 'retirement', 'decided_by' => 'Conseil'], $asset->number,
            files: [new UploadedFile($tmp, 'pv.pdf', 'application/pdf', null, true)], documentType: $type);

        echo 'OK '.$disposal->reference.PHP_EOL;
        echo 'DOCUMENTS '.Document::query()->count().PHP_EOL;
        echo 'FILES '.count(Storage::disk('local')->allFiles()).PHP_EOL;
        echo 'SAME '.(Storage::disk('local')->allFiles() === [Document::query()->value('path')] ? 'yes' : 'no').PHP_EOL;
        break;

    case 'report':
        $rows = DB::table('disposals')->join('assets', 'assets.id', '=', 'disposals.asset_id')->orderBy('reference')->get(['reference', 'number', 'net_book_value', 'status']);

        foreach ($rows as $row) {
            echo $row->reference.' '.$row->number.' '.$row->net_book_value.' '.$row->status.PHP_EOL;
        }

        $amounts = DB::table('depreciation_lines')
            ->join('depreciation_schedules', 'depreciation_schedules.id', '=', 'depreciation_lines.depreciation_schedule_id')
            ->where('depreciation_schedules.is_current', true)
            ->where('depreciation_lines.fiscal_year', 2026)
            ->pluck('amount')->unique()->values()->all();

        echo 'LINES2026 '.implode(',', $amounts).PHP_EOL;
        echo 'SCHEDULES '.DB::table('depreciation_schedules')->where('is_current', true)->count().PHP_EOL;
        break;
}

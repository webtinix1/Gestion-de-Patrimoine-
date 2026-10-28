<?php

/*
 * Processus lancés en parallèle par SortieConcurrenceTest : clôture de 2025 (exercice 2026 pas encore
 * créé, donc créé par la clôture) pendant une sortie datée de 2026 (RG-36, RG-43).
 * Usage : php tests/Support/concurrent-close-dispose.php setup|close|dispose|report
 */

use App\Actions\Assets\CreateAsset;
use App\Actions\Depreciation\RecalculateDepreciationSchedules;
use App\Actions\Depreciation\SyncDepreciationSchedule;
use App\Actions\Disposals\RecordDisposal;
use App\Actions\FiscalYears\CloseFiscalYear;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Enums\FiscalYearStatus;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

switch ($argv[1] ?? 'report') {
    case 'setup':
        foreach ([2024 => FiscalYearStatus::Closed, 2025 => FiscalYearStatus::Open] as $year => $status) {
            FiscalYear::query()->create(['year' => $year, 'label' => (string) $year, 'starts_on' => $year.'-01-01', 'ends_on' => $year.'-12-31', 'status' => $status]);
        }

        $category = AssetCategory::query()->create(['name' => 'Électrique', 'prefix' => 'ELE', 'depreciable' => Depreciability::Yes, 'default_duration_years' => 8]);
        $site = Site::query()->create(['name' => 'Siège', 'code' => 'SIE']);
        $department = Department::query()->create(['name' => 'Logistique', 'code' => 'LOG']);
        $condition = AssetCondition::query()->create(['label' => 'Bon', 'sort' => 1]);
        app(CreateAsset::class)([
            'designation' => 'Groupe électrogène', 'asset_category_id' => $category->id, 'acquired_on' => '2022-06-15', 'in_service_on' => '2022-06-15',
            'acquisition_cost' => 4800000, 'site_id' => $site->id, 'department_id' => $department->id, 'asset_condition_id' => $condition->id,
            'status' => AssetStatus::InService->value,
        ]);
        User::factory()->admin()->create(['username' => 'admin.concurrence']);
        break;

    case 'close':
        // Clôture ralentie après le recalcul (verrous des biens pris) : la sortie arrive pendant ce temps.
        $app->bind(RecalculateDepreciationSchedules::class, fn ($app) => new class($app->make(SyncDepreciationSchedule::class)) extends RecalculateDepreciationSchedules
        {
            public function __invoke(): array
            {
                $report = parent::__invoke();
                sleep(3);

                return $report;
            }
        });

        try {
            app(CloseFiscalYear::class)(FiscalYear::query()->where('year', 2025)->sole(), User::query()->where('username', 'admin.concurrence')->sole(), acknowledged: true);
            echo 'CLOSE OK'.PHP_EOL;
        } catch (Throwable $exception) {
            echo 'CLOSE FAIL '.get_class($exception).' '.substr($exception->getMessage(), 0, 160).PHP_EOL;
        }
        break;

    case 'close-deadlock': // interblocage simulé à la 1re tentative de la clôture (victime désignée par la base)
        $app->bind(RecalculateDepreciationSchedules::class, fn ($app) => new class($app->make(SyncDepreciationSchedule::class)) extends RecalculateDepreciationSchedules
        {
            public static int $calls = 0;

            public function __invoke(): array
            {
                if (++self::$calls === 1) {
                    throw new DeadlockException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction');
                }

                return parent::__invoke();
            }
        });

        try {
            app(CloseFiscalYear::class)(FiscalYear::query()->where('year', 2025)->sole(), User::query()->where('username', 'admin.concurrence')->sole(), acknowledged: true);
            echo 'CLOSE OK'.PHP_EOL;
        } catch (Throwable $exception) {
            echo 'CLOSE FAIL '.get_class($exception).PHP_EOL;
        }
        break;

    case 'dispose':
        usleep(1000000);
        $asset = Asset::query()->sole();

        try {
            $disposal = app(RecordDisposal::class)($asset, ['disposed_on' => '2026-10-26', 'reason' => 'retirement', 'decided_by' => 'Conseil'], $asset->number);
            echo 'DISPOSE OK '.$disposal->net_book_value.PHP_EOL;
        } catch (Throwable $exception) {
            echo 'DISPOSE FAIL '.get_class($exception).' '.substr($exception->getMessage(), 0, 160).PHP_EOL;
        }
        break;

    case 'report':
        echo 'YEARS '.FiscalYear::query()->orderBy('year')->get()->map(fn (FiscalYear $year): string => $year->year.':'.$year->status->value)->implode(',').PHP_EOL;
        echo 'LINES '.DB::table('depreciation_lines')
            ->join('depreciation_schedules', 'depreciation_schedules.id', '=', 'depreciation_lines.depreciation_schedule_id')
            ->where('depreciation_schedules.is_current', true)
            ->orderBy('fiscal_year')
            ->get(['fiscal_year', 'amount', 'is_locked'])
            ->map(fn (object $line): string => $line->fiscal_year.':'.$line->amount.':'.(int) $line->is_locked)
            ->implode(',').PHP_EOL;
        break;
}

<?php

/*
 * Processus lancé en parallèle par OperationsConcurrenceTest (RG-55, RG-56, RG-60, RG-63, RG-86).
 * Usage : php tests/Support/concurrent-operations.php setup|loan|open|report|return|dump <argument>
 */

use App\Actions\Loans\CreateLoan;
use App\Actions\Loans\RecordLoanReturn;
use App\Actions\Maintenance\OpenMaintenanceJob;
use App\Actions\Reports\CreateAssetReport;
use App\Enums\BorrowerType;
use App\Enums\LoanDirection;
use App\Enums\MaintenanceNature;
use App\Enums\ProviderType;
use App\Enums\ReportType;
use App\Enums\ReportUrgency;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Loan;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'dump';
$arg = (int) ($argv[2] ?? 1);

try {
    switch ($mode) {
        case 'setup': // $arg biens En service
            $site = Site::query()->create(['name' => 'Siège', 'code' => 'SIE']);
            $department = Department::query()->create(['name' => 'Administration', 'code' => 'ADM']);
            $condition = AssetCondition::query()->create(['label' => 'Bon état', 'sort' => 3]);
            User::factory()->create();
            Asset::factory()->count($arg)->create(['site_id' => $site->id, 'department_id' => $department->id, 'asset_condition_id' => $condition->id]);
            break;

        case 'loan': // prête le bien $arg
            usleep(random_int(0, 2000));

            try {
                echo 'OK '.app(CreateLoan::class)([
                    'direction' => LoanDirection::Out,
                    'borrower_type' => BorrowerType::External,
                    'borrower_name' => 'Chorale '.getmypid(),
                    'out_on' => today()->toDateString(),
                    'due_on' => today()->addDays(5)->toDateString(),
                    'responsible_id' => User::query()->value('id'),
                ], [$arg])->reference.PHP_EOL;
            } catch (ValidationException) {
                echo 'REFUS'.PHP_EOL;
            }
            break;

        case 'open': // ouvre une intervention sur le bien $arg
            usleep(random_int(0, 2000));

            try {
                echo 'OK '.app(OpenMaintenanceJob::class)([
                    'asset_id' => $arg,
                    'nature' => MaintenanceNature::Repair,
                    'reason' => 'Panne',
                    'starts_on' => today()->toDateString(),
                    'provider_type' => ProviderType::Internal,
                    'put_in_maintenance' => true,
                ])->reference.PHP_EOL;
            } catch (ValidationException) {
                echo 'REFUS'.PHP_EOL;
            }
            break;

        case 'report': // signale le bien $arg
            echo 'OK '.app(CreateAssetReport::class)([
                'asset_id' => $arg,
                'type' => ReportType::Maintenance,
                'urgency' => ReportUrgency::Normal,
                'description' => 'Grésille',
            ])->reference.PHP_EOL;
            break;

        case 'return': // enregistre le retour du prêt $arg, chargé avant comme un écran resté ouvert
            $loan = Loan::query()->with('items')->findOrFail($arg);
            usleep(random_int(0, 2000));

            try {
                app(RecordLoanReturn::class)($loan, [
                    'returned_on' => today()->toDateString(),
                    'items' => $loan->items->mapWithKeys(fn ($item) => [$item->id => ['condition_back_id' => $item->condition_out_id]])->all(),
                ]);
                echo 'OK'.PHP_EOL;
            } catch (ValidationException) {
                echo 'REFUS'.PHP_EOL;
            }
            break;

        case 'dump':
            foreach (DB::table('assets')->orderBy('id')->get(['id', 'status']) as $row) {
                echo 'ASSET '.$row->id.' '.$row->status.PHP_EOL;
            }

            foreach (DB::table('loans')->orderBy('id')->get(['reference', 'status']) as $row) {
                echo 'LOAN '.$row->reference.' '.$row->status.PHP_EOL;
            }
            break;
    }
} catch (Throwable $exception) {
    echo 'ERREUR '.get_class($exception).' '.$exception->getMessage().PHP_EOL;
}

<?php

use App\Actions\Maintenance\CloseMaintenanceJob;
use App\Actions\Maintenance\OpenMaintenanceJob;
use App\Actions\Reports\CreateAssetReport;
use App\Actions\Reports\ProcessAssetReport;
use App\Enums\AssetStatus;
use App\Enums\MaintenanceNature;
use App\Enums\MaintenanceResult;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\ReportUrgency;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetReport;
use App\Models\MaintenanceJob;
use App\Models\Movement;
use App\Models\Room;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperationsWorld;

beforeEach(function () {
    Storage::fake('local');
    $this->w = OperationsWorld::create();
    $this->head = $this->w->head($this->w->louange);
    $this->asset = $this->w->asset();
});

function reportData(array $overrides = []): array
{
    return [
        'asset_id' => test()->asset->id,
        'type' => ReportType::Maintenance,
        'urgency' => ReportUrgency::Normal,
        'description' => 'Le canal B grésille puis coupe après 20 minutes.',
        ...$overrides,
    ];
}

function makeReport(array $overrides = [], ?User $as = null): AssetReport
{
    test()->actingAs($as ?? test()->head);
    $report = app(CreateAssetReport::class)(reportData($overrides));
    test()->actingAs(test()->w->manager);

    return $report;
}

it('le responsable signale un besoin de maintenance sur un bien de son périmètre sans modifier le bien (RG-63)', function () {
    $before = $this->asset->only(['status', 'asset_condition_id', 'room_id', 'department_id', 'lock_version', 'updated_at']);
    $this->actingAs($this->head);

    $report = app(CreateAssetReport::class)(reportData());

    expect($report->reference)->toMatch('/^SIG-\d{4}-001$/')
        ->and($report->status)->toBe(ReportStatus::New)
        ->and($report->type)->toBe(ReportType::Maintenance)
        ->and($report->urgency)->toBe(ReportUrgency::Normal)
        ->and($report->created_by)->toBe($this->head->id)
        ->and($this->asset->fresh()->only(['status', 'asset_condition_id', 'room_id', 'department_id', 'lock_version', 'updated_at']))->toEqual($before);
});

it('signale un changement d\'état avec l\'état constaté (RG-63)', function () {
    $report = makeReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->aReparer->id]);

    expect($report->reported_condition_id)->toBe($this->w->aReparer->id)
        ->and($this->asset->fresh()->asset_condition_id)->toBe($this->w->bon->id);

    expect(fn () => makeReport(['type' => ReportType::Condition]))->toThrow(ValidationException::class)
        ->and(fn () => makeReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->bon->id]))->toThrow(ValidationException::class);
});

it('signale un déplacement avec le local ou le département indiqué (RG-63)', function () {
    $room = Room::factory()->create(['site_id' => $this->w->site->id, 'department_id' => $this->w->louange->id]);

    $report = makeReport(['type' => ReportType::Move, 'reported_room_id' => $room->id, 'reported_department_id' => $this->w->jeunesse->id]);

    expect($report->reported_room_id)->toBe($room->id)
        ->and($report->reported_department_id)->toBe($this->w->jeunesse->id)
        ->and($report->reportedDepartment->name)->toBe('Jeunesse')
        ->and(fn () => makeReport(['type' => ReportType::Move]))->toThrow(ValidationException::class)
        ->and(fn () => makeReport(['type' => ReportType::Move, 'reported_department_id' => $this->w->louange->id]))->toThrow(ValidationException::class);
});

it('exige nature, urgence et description', function () {
    foreach ([['type' => null], ['urgency' => null], ['description' => ' '], ['description' => 'ab'], ['asset_id' => null]] as $bad) {
        expect(fn () => makeReport($bad))->toThrow(ValidationException::class);
    }

    expect(AssetReport::query()->count())->toBe(0);
});

it('refuse un signalement sur un bien hors du périmètre du responsable (RG-63, RG-81)', function () {
    $other = $this->w->asset(['department_id' => $this->w->jeunesse->id]);

    expect(fn () => makeReport(['asset_id' => $other->id]))->toThrow(ValidationException::class)
        ->and(AssetReport::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuse un signalement sur un bien sorti du patrimoine', function () {
    $disposed = Asset::factory()->disposed()->create(['site_id' => $this->w->site->id, 'department_id' => $this->w->louange->id, 'asset_condition_id' => $this->w->bon->id]);

    expect(fn () => makeReport(['asset_id' => $disposed->id]))->toThrow(ValidationException::class);
});

it('le gestionnaire sans signalement dans la matrice ne peut pas signaler : droits (matrice)', function () {
    $viewer = User::factory()->role(DefaultRoles::VIEWER)->create();
    $accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();

    foreach ([$viewer, $accountant] as $user) {
        $this->actingAs($user);

        expect(fn () => app(CreateAssetReport::class)(reportData()))->toThrow(AuthorizationException::class);
    }

    expect($this->head->can('create', AssetReport::class))->toBeTrue()
        ->and($this->head->can('viewAny', AssetReport::class))->toBeTrue()
        ->and($this->head->can('handle', makeReport()))->toBeFalse()
        ->and($this->w->manager->can('handle', AssetReport::query()->firstOrFail()))->toBeTrue();
});

it('stocke la photo sur le disque privé, jamais sur un disque public (RG-63, RG-66)', function () {
    $this->actingAs($this->head);

    $report = app(CreateAssetReport::class)(reportData(), UploadedFile::fake()->image('canal.jpg'));

    expect($report->photo_path)->toStartWith('signalements/photos/');
    Storage::disk('local')->assertExists($report->photo_path);
    Storage::disk('public')->assertMissing($report->photo_path);
});

it('refuse un fichier qui n\'est pas une image et ne laisse aucun fichier derrière lui', function () {
    $this->actingAs($this->head);

    expect(fn () => app(CreateAssetReport::class)(reportData(), UploadedFile::fake()->create('virus.exe', 20)))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateAssetReport::class)(reportData(), UploadedFile::fake()->image('énorme.jpg')->size(6000)))->toThrow(ValidationException::class);

    expect(Storage::disk('local')->allFiles('signalements'))->toBe([]);
});

it('supprime la photo si le signalement n\'a pas pu être enregistré', function () {
    $this->actingAs($this->head);

    try {
        app(CreateAssetReport::class)(reportData(['description' => '']), UploadedFile::fake()->image('canal.jpg'));
    } catch (ValidationException) {
    }

    expect(Storage::disk('local')->allFiles('signalements'))->toBe([]);
});

it('trace le signalement au journal', function () {
    $report = makeReport();

    $log = Activity::query()->where('log_name', 'signalements')->where('event', 'reported')->sole();

    expect($log->subject_id)->toBe($this->asset->id)
        ->and($log->causer_id)->toBe($this->head->id)
        ->and($log->properties['attributes']['reference'])->toBe($report->reference);
});

it('numérote les signalements de façon unique (SIG)', function () {
    $first = makeReport();
    $second = makeReport();

    expect($first->reference)->toBe('SIG-'.today()->year.'-001')
        ->and($second->reference)->toBe('SIG-'.today()->year.'-002');
});

it('le responsable ne voit que les signalements de son périmètre (RG-81)', function () {
    $mine = makeReport();
    $other = $this->w->asset(['department_id' => $this->w->jeunesse->id]);
    $jeunesseHead = $this->w->head($this->w->jeunesse);
    $theirs = makeReport(['asset_id' => $other->id], $jeunesseHead);

    $this->actingAs($this->head);

    expect(AssetReport::query()->pluck('id')->all())->toBe([$mine->id])
        ->and(AssetReport::query()->find($theirs->id))->toBeNull();

    $this->actingAs($this->w->manager);

    expect(AssetReport::query()->count())->toBe(2);
});

it('prend en charge un signalement et passe par le droit « valider » (RG-64)', function () {
    $report = makeReport();

    $taken = app(ProcessAssetReport::class)->takeCharge($report);

    expect($taken->status)->toBe(ReportStatus::InProgress)
        ->and($taken->handled_by)->toBe($this->w->manager->id)
        ->and(fn () => app(ProcessAssetReport::class)->takeCharge($report))->toThrow(ValidationException::class);

    $this->actingAs($this->head);

    expect(fn () => app(ProcessAssetReport::class)->reject($report, 'Non'))->toThrow(AuthorizationException::class);
});

it('rejette avec un motif obligatoire, et le responsable en voit le statut (RG-64)', function () {
    $report = makeReport();

    expect(fn () => app(ProcessAssetReport::class)->reject($report, ' '))->toThrow(ValidationException::class);

    $rejected = app(ProcessAssetReport::class)->reject($report, 'Usure normale, à resignaler si la panne se répète.');

    expect($rejected->status)->toBe(ReportStatus::Rejected)
        ->and($rejected->rejection_reason)->toContain('Usure normale')
        ->and($rejected->handled_at)->not->toBeNull()
        ->and($this->asset->fresh()->status)->toBe(AssetStatus::InService);

    $this->actingAs($this->head);
    $seen = AssetReport::query()->findOrFail($report->id);

    expect($seen->status->getLabel())->toBe('Rejeté')
        ->and($seen->rejection_reason)->toContain('Usure normale')
        ->and(fn () => $seen->update(['description' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => app(ProcessAssetReport::class)->takeCharge($seen))->toThrow(AuthorizationException::class);

    $this->actingAs($this->w->manager);

    expect(fn () => app(ProcessAssetReport::class)->reject($report, 'Encore'))->toThrow(ValidationException::class);
});

it('traite un besoin de maintenance en créant l\'intervention : Pris en charge, puis Traité à la clôture (RG-64)', function () {
    $report = makeReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->aReparer->id]);

    $job = app(OpenMaintenanceJob::class)([
        'asset_id' => $this->asset->id, 'nature' => MaintenanceNature::Repair, 'reason' => 'Signalement', 'starts_on' => today()->toDateString(),
        'provider_type' => ProviderType::Internal, 'put_in_maintenance' => true, 'report_id' => $report->id,
    ]);

    $linked = $report->fresh();

    expect($linked->status)->toBe(ReportStatus::InProgress)
        ->and($linked->handledRef)->toBeInstanceOf(MaintenanceJob::class)
        ->and($linked->handledRef->is($job))->toBeTrue()
        ->and($linked->handled_by)->toBe($this->w->manager->id);

    app(CloseMaintenanceJob::class)($job, [
        'result' => MaintenanceResult::Repaired, 'condition_after_id' => $this->w->bon->id, 'status_after' => AssetStatus::InService, 'ends_on' => today()->toDateString(),
    ]);

    expect($report->fresh()->status)->toBe(ReportStatus::Handled)
        ->and($report->fresh()->handled_at)->not->toBeNull();
});

it('refuse de lier une intervention à un signalement d\'un autre bien, déjà traité, de déplacement ou sans droit', function () {
    $report = makeReport();
    $other = $this->w->asset();
    $jobData = fn (Asset $asset, array $extra = []) => [
        'asset_id' => $asset->id, 'nature' => MaintenanceNature::Repair, 'reason' => 'x', 'starts_on' => today()->toDateString(),
        'provider_type' => ProviderType::Internal, ...$extra,
    ];

    expect(fn () => app(OpenMaintenanceJob::class)($jobData($other, ['report_id' => $report->id])))->toThrow(ValidationException::class)
        ->and(MaintenanceJob::query()->count())->toBe(0);

    $move = makeReport(['type' => ReportType::Move, 'reported_department_id' => $this->w->jeunesse->id]);

    expect(fn () => app(OpenMaintenanceJob::class)($jobData($this->asset, ['report_id' => $move->id])))->toThrow(ValidationException::class);

    app(ProcessAssetReport::class)->reject($report, 'Déjà réglé');

    expect(fn () => app(OpenMaintenanceJob::class)($jobData($this->asset, ['report_id' => $report->id])))->toThrow(ValidationException::class);

    $accountantLike = User::factory()->create();
    $accountantLike->givePermissionTo(['maintenance.create', 'maintenance.view']);
    $this->actingAs($accountantLike);

    expect(fn () => app(OpenMaintenanceJob::class)($jobData($this->asset, ['report_id' => $move->id])))->toThrow(AuthorizationException::class);
});

it('traite un déplacement par un transfert via TransferAssets : mouvement créé, bien transféré, signalement Traité (RG-64)', function () {
    $report = makeReport(['type' => ReportType::Move, 'reported_department_id' => $this->w->jeunesse->id]);
    $version = $this->asset->lock_version;

    $done = app(ProcessAssetReport::class)->transfer($report, [
        'site_id' => $this->w->site->id, 'department_id' => $this->w->jeunesse->id, 'moved_on' => today()->toDateString(), 'reason' => 'Signalement '.$report->reference,
    ], $version);

    $movement = Movement::query()->where('asset_id', $this->asset->id)->sole();

    expect($done->status)->toBe(ReportStatus::Handled)
        ->and($done->handledRef)->toBeInstanceOf(Movement::class)
        ->and($done->handledRef->is($movement))->toBeTrue()
        ->and($this->asset->fresh()->department_id)->toBe($this->w->jeunesse->id)
        ->and($this->asset->fresh()->lock_version)->toBeGreaterThan($version)
        ->and($movement->from_department_id)->toBe($this->w->louange->id)
        ->and(Activity::query()->where('log_name', 'mouvements')->where('event', 'transferred')->count())->toBe(1);
});

it('un transfert refusé ne traite pas le signalement (tout ou rien)', function () {
    $report = makeReport(['type' => ReportType::Move, 'reported_department_id' => $this->w->jeunesse->id]);

    expect(fn () => app(ProcessAssetReport::class)->transfer($report, [
        'site_id' => $this->w->site->id, 'department_id' => $this->w->louange->id, 'moved_on' => today()->toDateString(),
    ]))->toThrow(ValidationException::class)
        ->and($report->fresh()->status)->toBe(ReportStatus::New)
        ->and(Movement::query()->count())->toBe(0);

    expect(fn () => app(ProcessAssetReport::class)->transfer($report, [
        'site_id' => $this->w->site->id, 'department_id' => $this->w->jeunesse->id, 'moved_on' => today()->toDateString(),
    ], $this->asset->lock_version - 1))->toThrow(ConcurrentModificationException::class)
        ->and($report->fresh()->status)->toBe(ReportStatus::New);
});

it('le transfert ne traite que les signalements de déplacement', function () {
    $report = makeReport();

    expect(fn () => app(ProcessAssetReport::class)->transfer($report, [
        'site_id' => $this->w->site->id, 'department_id' => $this->w->jeunesse->id, 'moved_on' => today()->toDateString(),
    ]))->toThrow(ValidationException::class)
        ->and(fn () => app(ProcessAssetReport::class)->updateCondition($report, $this->w->moyen->id))->toThrow(ValidationException::class);
});

it('traite un changement d\'état par UpdateAsset : état mis à jour, version incrémentée, paramètres d\'amortissement conservés (RG-64, RG-86)', function () {
    $asset = $this->w->asset(['acquisition_cost' => 540000, 'is_depreciable' => false, 'residual_value' => 0, 'notes' => 'Note conservée']);
    $report = makeReport(['asset_id' => $asset->id, 'type' => ReportType::Condition, 'reported_condition_id' => $this->w->aReparer->id]);
    $version = $asset->lock_version;

    $done = app(ProcessAssetReport::class)->updateCondition($report, $this->w->aReparer->id, $version);

    expect($done->status)->toBe(ReportStatus::Handled)
        ->and($done->handledRef->is($asset))->toBeTrue()
        ->and($asset->fresh()->asset_condition_id)->toBe($this->w->aReparer->id)
        ->and($asset->fresh()->lock_version)->toBeGreaterThan($version)
        ->and($asset->fresh()->acquisition_cost)->toBe(540000)
        ->and($asset->fresh()->notes)->toBe('Note conservée')
        ->and(Activity::query()->where('log_name', 'signalements')->where('event', 'condition_updated')->sole()->properties['old']['condition'])->toBe('Bon état');
});

it('la mise à jour d\'état contrôle la version de la fiche (RG-86)', function () {
    $report = makeReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->aReparer->id]);

    expect(fn () => app(ProcessAssetReport::class)->updateCondition($report, $this->w->aReparer->id, $this->asset->lock_version - 1))->toThrow(ConcurrentModificationException::class)
        ->and($this->asset->fresh()->asset_condition_id)->toBe($this->w->bon->id)
        ->and($report->fresh()->status)->toBe(ReportStatus::New);
});

it('un signalement traité par un autre gestionnaire entre-temps n\'est pas traité deux fois', function () {
    $report = makeReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->aReparer->id]);
    $stale = AssetReport::query()->find($report->id);

    app(ProcessAssetReport::class)->updateCondition($report, $this->w->aReparer->id);

    expect(fn () => app(ProcessAssetReport::class)->updateCondition($stale, $this->w->moyen->id))->toThrow(ValidationException::class)
        ->and($this->asset->fresh()->asset_condition_id)->toBe($this->w->aReparer->id);
});

it('un gestionnaire au périmètre restreint ne traite pas un signalement hors périmètre (RG-81)', function () {
    $report = makeReport();
    $restricted = $this->w->restrictedManager($this->w->jeunesse);
    $this->actingAs($restricted);

    expect(fn () => app(ProcessAssetReport::class)->takeCharge($report))->toThrow(ValidationException::class)
        ->and(fn () => app(ProcessAssetReport::class)->reject($report, 'Hors périmètre'))->toThrow(ValidationException::class)
        ->and($report->fresh()->status)->toBe(ReportStatus::New);
});

it('un signalement ne se modifie pas et ne se supprime pas (policy et modèle)', function () {
    $report = makeReport();

    expect(fn () => $report->delete())->toThrow(LogicException::class)
        ->and($this->w->manager->can('update', $report))->toBeFalse()
        ->and($this->w->manager->can('delete', $report))->toBeFalse()
        ->and($this->w->manager->can('deleteAny', AssetReport::class))->toBeFalse();
});

it('marque comme traité un signalement pris en charge hors de l\'application (sortie)', function () {
    $report = makeReport(['type' => ReportType::Condition, 'reported_condition_id' => $this->w->horsService->id]);

    expect(fn () => app(ProcessAssetReport::class)->markHandled($report))->toThrow(ValidationException::class);

    app(ProcessAssetReport::class)->takeCharge($report);

    expect(app(ProcessAssetReport::class)->markHandled($report)->status)->toBe(ReportStatus::Handled);
});

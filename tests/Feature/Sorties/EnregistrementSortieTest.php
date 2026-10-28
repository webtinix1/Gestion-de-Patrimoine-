<?php

use App\Actions\Assets\UpdateAsset;
use App\Actions\Disposals\RecordDisposal;
use App\Enums\AssetStatus;
use App\Enums\Depreciability;
use App\Enums\DisposalAccountingStatus;
use App\Enums\DisposalReason;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\DepreciationSchedule;
use App\Models\Disposal;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\DisposalValuation;
use App\Support\DefaultRoles;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    disposalFiscalYears();
});

afterEach(fn () => Carbon::setTestNow());

it('arrête le plan à la date de sortie et fige les valeurs du cas A5, de bout en bout (RG-39)', function () {
    $case = depreciationCase('A5');
    $asset = a5Asset();
    $locked = lineSnapshot($asset);
    $preview = app(DisposalValuation::class)->preview($asset, CarbonImmutable::parse($case['date_sortie']));

    $disposal = recordDisposal($asset, ['disposed_on' => $case['date_sortie']]);
    $asset->refresh();

    expect(scheduleLines($asset))->toBe($case['attendu'])
        ->and($disposal->original_value)->toBe(4800000)
        ->and($disposal->accumulated_depreciation)->toBe(2620000)
        ->and($disposal->net_book_value)->toBe(2180000)
        ->and(currentSchedule($asset)->lines->firstWhere('fiscal_year', 2026)->amount)->toBe(493333)
        ->and($preview->yearAmount)->toBe(493333)
        ->and([$preview->originalValue, $preview->accumulatedDepreciation, $preview->netBookValue])->toBe([4800000, 2620000, 2180000])
        ->and($asset->status)->toBe(AssetStatus::Retired)
        ->and($asset->disposed_at?->toDateString())->toBe('2026-10-26')
        ->and($disposal->reference)->toBe('SRT-2026-001')
        ->and($disposal->accounting_status)->toBe(DisposalAccountingStatus::Pending)
        ->and($disposal->previous_status)->toBe(AssetStatus::InService)
        // RG-36 : les lignes des exercices clôturés n'ont pas bougé.
        ->and(lineSnapshot($asset))->toBe($locked);
});

it('garde la version validée du plan et en crée une nouvelle, arrêtée à la sortie (RG-38, RG-36)', function () {
    $asset = a5Asset();
    $schedule = currentSchedule($asset);
    $schedule->forceFill(['validated_at' => now(), 'validated_by' => null])->save();
    $before = $schedule->lines->map->getAttributes()->all();

    recordDisposal($asset);

    $old = DepreciationSchedule::query()->with('lines')->find($schedule->id);

    expect($old->is_current)->toBeFalse()
        ->and($old->lines->map->getAttributes()->all())->toBe($before)
        ->and(currentSchedule($asset)->version)->toBe(2)
        ->and(currentSchedule($asset)->lines->max('fiscal_year'))->toBe(2026);
});

it('donne le statut Cédé pour une cession ou un don et Réformé pour les autres motifs (RG-52)', function (DisposalReason $reason, AssetStatus $status) {
    $asset = createDepreciableAsset();

    recordDisposal($asset, ['reason' => $reason->value, 'buyer_or_beneficiary' => 'Énergie Solaire Plus', 'sale_price' => '500000']);

    expect($asset->fresh()->status)->toBe($status)
        ->and($asset->fresh()->isDisposed())->toBeTrue();
})->with([
    'cession' => [DisposalReason::Sale, AssetStatus::TransferredOut],
    'don' => [DisposalReason::Donation, AssetStatus::TransferredOut],
    'réforme' => [DisposalReason::Retirement, AssetStatus::Retired],
    'destruction' => [DisposalReason::Destruction, AssetStatus::Retired],
    'perte' => [DisposalReason::Loss, AssetStatus::Retired],
    'vol' => [DisposalReason::Theft, AssetStatus::Retired],
    'autre motif' => [DisposalReason::Other, AssetStatus::Retired],
]);

it('exige l\'acquéreur et un prix entier pour une cession, et donne la moins-value indicative (RG-51, Q8)', function () {
    $asset = a5Asset();

    expect(fn () => recordDisposal($asset, ['reason' => 'sale']))->toThrow(ValidationException::class)
        ->and(fn () => recordDisposal($asset, ['reason' => 'sale', 'buyer_or_beneficiary' => 'Énergie Solaire Plus', 'sale_price' => '500000.50']))->toThrow(ValidationException::class)
        ->and(fn () => recordDisposal($asset, ['reason' => 'sale', 'buyer_or_beneficiary' => 'Énergie Solaire Plus', 'sale_price' => '-1']))->toThrow(ValidationException::class);

    $disposal = recordDisposal($asset, ['reason' => 'sale', 'buyer_or_beneficiary' => 'Énergie Solaire Plus', 'sale_price' => '500 000']);

    expect($disposal->sale_price)->toBe(500000)
        ->and($disposal->buyer_or_beneficiary)->toBe('Énergie Solaire Plus')
        ->and($disposal->indicativeGain())->toBe(-1680000);
});

it('exige le bénéficiaire d\'un don et ignore le prix hors cession', function () {
    $asset = createDepreciableAsset();

    expect(fn () => recordDisposal($asset, ['reason' => 'donation']))->toThrow(ValidationException::class);

    $disposal = recordDisposal($asset, ['reason' => 'retirement', 'buyer_or_beneficiary' => 'Quelqu\'un', 'sale_price' => '1000']);

    expect($disposal->sale_price)->toBeNull()
        ->and($disposal->buyer_or_beneficiary)->toBeNull()
        ->and($disposal->indicativeGain())->toBeNull();
});

it('exige le décideur, le motif et la date (RG-51)', function (array $data, string $field) {
    $asset = createDepreciableAsset();

    try {
        recordDisposal($asset, $data);
        $this->fail('Sortie acceptée');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect($asset->fresh()->isDisposed())->toBeFalse();
})->with([
    'décideur' => [['decided_by' => '  '], 'decided_by'],
    'motif' => [['reason' => 'vente'], 'reason'],
    'date' => [['disposed_on' => null], 'disposed_on'],
]);

it('n\'accepte la sortie que sur la saisie exacte du numéro du bien (RG-53)', function () {
    $asset = createDepreciableAsset();

    foreach ([null, '', strtolower($asset->number), 'PAT-999999'] as $wrong) {
        try {
            app(RecordDisposal::class)($asset, ['disposed_on' => '2026-10-26', 'reason' => 'retirement', 'decided_by' => 'Conseil'], $wrong);
            $this->fail('Sortie acceptée sans confirmation');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('confirmation');
        }
    }

    expect(Disposal::query()->count())->toBe(0)
        ->and($asset->fresh()->isDisposed())->toBeFalse();

    recordDisposal($asset, confirmation: '  '.$asset->number.' ');

    expect($asset->fresh()->isDisposed())->toBeTrue();
});

it('refuse un bien déjà sorti', function () {
    $asset = createDepreciableAsset();
    recordDisposal($asset);

    expect(fn () => recordDisposal($asset->fresh(), ['disposed_on' => '2026-10-27']))->toThrow(ValidationException::class, 'déjà sorti')
        ->and(Disposal::query()->count())->toBe(1);
});

it('refuse un bien emprunté ou en maintenance (QL10-1)', function (AssetStatus $status) {
    $asset = createDepreciableAsset();
    Asset::query()->whereKey($asset->id)->update(['status' => $status->value]);

    expect(fn () => recordDisposal($asset->fresh()))->toThrow(ValidationException::class, 'enregistrez d\'abord')
        ->and($asset->fresh()->isDisposed())->toBeFalse();
})->with([AssetStatus::OnLoan, AssetStatus::InMaintenance]);

it('accepte un bien hors service ou en stock', function (AssetStatus $status) {
    $asset = createDepreciableAsset(['status' => $status->value]);

    recordDisposal($asset);

    expect($asset->fresh()->status)->toBe(AssetStatus::Retired)
        ->and(Disposal::query()->sole()->previous_status)->toBe($status);
})->with([AssetStatus::OutOfService, AssetStatus::InStock]);

it('refuse une date future ou antérieure à l\'acquisition ou à la mise en service', function (string $date) {
    $asset = createDepreciableAsset(['acquired_on' => '2024-01-10', 'in_service_on' => '2024-03-18']);

    expect(fn () => recordDisposal($asset, ['disposed_on' => $date]))->toThrow(ValidationException::class)
        ->and($asset->fresh()->isDisposed())->toBeFalse();
})->with(['2026-10-29', '2024-01-09', '2024-03-17']);

it('refuse une sortie datée dans un exercice clôturé, sans toucher aucune ligne figée (Q9.12, RG-36)', function (string $date) {
    $asset = a5Asset();
    $before = lineSnapshot($asset, lockedOnly: false);
    $schedule = currentSchedule($asset)->getAttributes();

    expect(fn () => recordDisposal($asset, ['disposed_on' => $date]))->toThrow(ValidationException::class, 'figées')
        ->and(lineSnapshot($asset, lockedOnly: false))->toBe($before)
        ->and(currentSchedule($asset)->getAttributes())->toBe($schedule)
        ->and($asset->fresh()->isDisposed())->toBeFalse()
        ->and(Disposal::query()->count())->toBe(0);
})->with(['clôturé' => '2025-12-31', 'antérieur au premier exercice' => '2022-12-01']);

it('accepte sans exercice saisi', function () {
    FiscalYear::query()->delete();
    $asset = createDepreciableAsset();

    expect(recordDisposal($asset)->reference)->toBe('SRT-2026-001');
});

it('refuse un bien hors du périmètre de l\'utilisateur (RG-81)', function () {
    $own = Department::factory()->create();
    $manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $manager->departments()->attach($own);
    $asset = createDepreciableAsset();
    $this->actingAs($manager);

    expect(fn () => recordDisposal($asset))->toThrow(ValidationException::class, 'hors de votre périmètre')
        ->and(Asset::query()->withoutGlobalScopes()->find($asset->id)->isDisposed())->toBeFalse();
});

it('refuse la sortie sans le droit de créer une sortie (matrice)', function (string $role) {
    $asset = createDepreciableAsset();
    $this->actingAs(User::factory()->role($role)->create());

    expect(fn () => recordDisposal($asset))->toThrow(AuthorizationException::class)
        ->and($asset->fresh()->isDisposed())->toBeFalse();
})->with([DefaultRoles::ACCOUNTANT, DefaultRoles::VIEWER, DefaultRoles::DEPARTMENT_HEAD]);

it('laisse le gestionnaire et l\'administrateur enregistrer une sortie', function (string $role) {
    $asset = createDepreciableAsset();
    $user = User::factory()->role($role)->create();
    $this->actingAs($user);

    expect(recordDisposal($asset)->created_by)->toBe($user->id);
})->with([DefaultRoles::MANAGER, DefaultRoles::ADMIN]);

it('refuse une version périmée de la fiche (RG-86)', function () {
    $asset = createDepreciableAsset();
    $opened = $asset->fresh()->lock_version;
    app(UpdateAsset::class)($asset->fresh(), ['designation' => 'Portable renommé']);

    expect(fn () => recordDisposal($asset, version: $opened))->toThrow(ConcurrentModificationException::class)
        ->and($asset->fresh()->isDisposed())->toBeFalse();

    recordDisposal($asset, version: $asset->fresh()->lock_version);

    expect($asset->fresh()->isDisposed())->toBeTrue();
});

it('fige pour un bien non amortissable une VNC égale à la valeur d\'origine (QL10-2)', function () {
    $category = AssetCategory::factory()->create(['prefix' => 'MOB', 'depreciable' => Depreciability::No]);
    $asset = createDepreciableAsset(['acquisition_cost' => 45000], $category);

    $disposal = recordDisposal($asset);

    expect($asset->fresh()->is_depreciable)->toBeFalse()
        ->and([$disposal->original_value, $disposal->accumulated_depreciation, $disposal->net_book_value])->toBe([45000, 0, 45000]);
});

it('fige un bien totalement amorti avec une VNC nulle', function () {
    $asset = createDepreciableAsset(['acquired_on' => '2021-01-01', 'in_service_on' => '2021-01-01', 'acquisition_cost' => 600000]);

    $disposal = recordDisposal($asset);

    expect([$disposal->original_value, $disposal->accumulated_depreciation, $disposal->net_book_value])->toBe([600000, 600000, 0])
        ->and(lineSnapshot($asset, lockedOnly: false))->not->toBeEmpty();
});

it('numérote les sorties par année, sans jamais réattribuer une référence', function () {
    $first = recordDisposal(createDepreciableAsset());
    $second = recordDisposal(createDepreciableAsset(), ['disposed_on' => '2026-01-05']);

    expect([$first->reference, $second->reference])->toBe(['SRT-2026-001', 'SRT-2026-002']);
});

it('rattache les justificatifs à la sortie et à la fiche du bien (RG-51)', function () {
    Storage::fake('local');
    $asset = createDepreciableAsset();
    $type = DocumentType::factory()->create(['label' => 'Document de réforme']);

    $disposal = app(RecordDisposal::class)(
        $asset,
        ['disposed_on' => '2026-10-26', 'reason' => 'retirement', 'decided_by' => 'Conseil'],
        $asset->number,
        files: [UploadedFile::fake()->create('PV CA 24-10-2026.pdf', 20, 'application/pdf')],
        documentType: $type,
    );

    expect($disposal->documents()->pluck('original_name')->all())->toBe(['PV CA 24-10-2026.pdf'])
        ->and($asset->documents()->count())->toBe(1);
});

it('annule tout si le plan ne peut pas être arrêté, sans laisser de fichier', function () {
    Storage::fake('local');
    $asset = createDepreciableAsset();
    $type = DocumentType::factory()->create();
    // Plan incohérent : valeur résiduelle supérieure au prix (saisie hors écran).
    Asset::query()->whereKey($asset->id)->update(['residual_value' => 9000000]);

    expect(fn () => app(RecordDisposal::class)(
        $asset,
        ['disposed_on' => '2026-10-26', 'reason' => 'retirement', 'decided_by' => 'Conseil'],
        $asset->number,
        files: [UploadedFile::fake()->create('pv.pdf', 20, 'application/pdf')],
        documentType: $type,
    ))->toThrow(ValidationException::class, 'ne peut pas être arrêté');

    expect($asset->fresh()->isDisposed())->toBeFalse()
        ->and(Disposal::query()->count())->toBe(0)
        ->and(Document::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('trace la sortie au journal avec le statut avant et après et les valeurs figées (RG-70)', function () {
    $manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($manager);
    $asset = a5Asset();

    $disposal = recordDisposal($asset);
    $entry = Activity::query()->where('log_name', 'sorties')->where('event', 'disposed')->sole();

    expect($entry->subject_id)->toBe($asset->id)
        ->and($entry->causer_id)->toBe($manager->id)
        ->and($entry->description)->toBe('Sortie du patrimoine')
        ->and($entry->properties['old'])->toBe(['status' => 'En service', 'disposed_at' => null])
        ->and($entry->properties['attributes'])->toMatchArray([
            'status' => 'Réformé', 'disposed_at' => '26/10/2026', 'reference' => $disposal->reference, 'reason' => 'Réforme',
            'original_value' => 4800000, 'accumulated_depreciation' => 2620000, 'net_book_value' => 2180000, 'accounting_status' => 'À valider',
        ])
        // Pas de doublon « Modification d'une fiche » pour la même opération.
        ->and(Activity::query()->where('subject_id', $asset->id)->where('log_name', 'biens')->where('event', 'updated')->count())->toBe(0);
});

it('ne peut ni modifier, ni annuler, ni supprimer une sortie, par aucun chemin (RG-53)', function () {
    $asset = createDepreciableAsset();
    $disposal = recordDisposal($asset);
    $admin = User::factory()->admin()->create();
    $frozen = $disposal->net_book_value;

    expect(fn () => $disposal->update(['net_book_value' => 0]))->toThrow(LogicException::class)
        ->and(fn () => $disposal->fresh()->forceFill(['disposed_on' => '2026-10-01'])->save())->toThrow(LogicException::class)
        ->and(fn () => $disposal->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $asset->fresh()->forceFill(['disposed_at' => null])->save())->toThrow(LogicException::class)
        ->and(fn () => $asset->fresh()->forceFill(['status' => AssetStatus::InService])->save())->toThrow(LogicException::class)
        ->and(fn () => app(UpdateAsset::class)($asset->fresh(), ['designation' => 'Autre']))->toThrow(ValidationException::class)
        ->and($admin->can('update', $disposal))->toBeFalse()
        ->and($admin->can('delete', $disposal))->toBeFalse()
        ->and($admin->can('update', $asset->fresh()))->toBeFalse()
        ->and($disposal->fresh()->net_book_value)->toBe($frozen)
        ->and($asset->fresh()->isDisposed())->toBeTrue();
});

it('ne recalcule plus le plan arrêté : recalcul des plans sans changement', function () {
    $asset = a5Asset();
    recordDisposal($asset);
    $snapshot = lineSnapshot($asset, lockedOnly: false);

    $this->artisan('patrimoine:recalculer-plans')->assertSuccessful();

    expect(lineSnapshot($asset, lockedOnly: false))->toBe($snapshot);
});

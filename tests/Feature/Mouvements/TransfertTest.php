<?php

use App\Actions\Assets\CreateAsset as CreateAssetAction;
use App\Actions\Assets\UpdateAsset;
use App\Actions\Movements\TransferAssets;
use App\Enums\MovementType;
use App\Exceptions\ConcurrentModificationException;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Holder;
use App\Models\Movement;
use App\Models\Room;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($this->user);

    $this->siege = Site::factory()->create(['name' => 'Siège']);
    $this->moungali = Site::factory()->create(['name' => 'Paroisse Moungali']);
    $this->louange = Department::factory()->create(['name' => 'Louange']);
    $this->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
    $this->chorale = Service::factory()->create(['department_id' => $this->louange->id, 'name' => 'Chorale']);
    $this->culteJeunes = Service::factory()->create(['department_id' => $this->jeunesse->id, 'name' => 'Culte des jeunes']);
    $this->salleCulte = Room::factory()->create(['site_id' => $this->siege->id, 'name' => 'Salle de culte']);
    $this->polyvalente = Room::factory()->create(['site_id' => $this->siege->id, 'name' => 'Salle polyvalente']);
    $this->ruth = Holder::factory()->create(['name' => 'Ruth Bouanga']);
    $this->david = Holder::factory()->create(['name' => 'David Massamba']);

    $this->asset = Asset::factory()->create([
        'number' => 'PAT-000087',
        'designation' => 'Chaise',
        'site_id' => $this->siege->id,
        'department_id' => $this->louange->id,
        'service_id' => $this->chorale->id,
        'room_id' => $this->salleCulte->id,
        'holder_id' => $this->ruth->id,
        'assigned_since' => '2024-01-10',
    ])->fresh();
});

function newAssignment(array $overrides = []): array
{
    return [
        'site_id' => test()->siege->id,
        'department_id' => test()->jeunesse->id,
        'service_id' => test()->culteJeunes->id,
        'room_id' => test()->polyvalente->id,
        'holder_id' => test()->david->id,
        'moved_on' => '2026-10-20',
        'reason' => 'Réaffectation entre départements',
        'notes' => 'Équipement du culte des jeunes.',
        ...$overrides,
    ];
}

it('transfère un bien et conserve l\'affectation précédente (RG-21)', function () {
    $movements = app(TransferAssets::class)([$this->asset], newAssignment());

    $movement = $movements->sole();
    $asset = $this->asset->fresh();

    expect($movement->asset_id)->toBe($this->asset->id)
        ->and($movement->type)->toBe(MovementType::Mixed)
        ->and($movement->from_department_id)->toBe($this->louange->id)
        ->and($movement->from_service_id)->toBe($this->chorale->id)
        ->and($movement->from_room_id)->toBe($this->salleCulte->id)
        ->and($movement->from_holder_id)->toBe($this->ruth->id)
        ->and($movement->from_site_id)->toBe($this->siege->id)
        ->and($movement->to_department_id)->toBe($this->jeunesse->id)
        ->and($movement->to_holder_id)->toBe($this->david->id)
        ->and($movement->moved_on->toDateString())->toBe('2026-10-20')
        ->and($movement->reason)->toBe('Réaffectation entre départements')
        ->and($movement->notes)->toBe('Équipement du culte des jeunes.')
        ->and($movement->created_by)->toBe($this->user->id)
        ->and($movement->batch_uuid)->toBeNull()
        ->and($asset->department_id)->toBe($this->jeunesse->id)
        ->and($asset->holder_id)->toBe($this->david->id)
        ->and($asset->assigned_since->toDateString())->toBe('2026-10-20')
        ->and($asset->number)->toBe('PAT-000087');
});

it('garde tout l\'historique : un second transfert ne réécrit pas le premier', function () {
    app(TransferAssets::class)([$this->asset], newAssignment());
    app(TransferAssets::class)([$this->asset], newAssignment([
        'department_id' => $this->louange->id, 'service_id' => $this->chorale->id, 'moved_on' => '2026-10-25',
    ]));

    $history = Movement::query()->where('asset_id', $this->asset->id)->orderBy('id')->get();

    expect($history)->toHaveCount(2)
        ->and($history[0]->to_department_id)->toBe($this->jeunesse->id)
        ->and($history[1]->from_department_id)->toBe($this->jeunesse->id)
        ->and($history[1]->to_department_id)->toBe($this->louange->id);
});

it('déduit le type de mouvement des éléments modifiés', function (array $change, MovementType $type) {
    $movement = app(TransferAssets::class)([$this->asset], [
        ...newAssignment([
            'site_id' => $this->siege->id, 'department_id' => $this->louange->id, 'service_id' => $this->chorale->id,
            'room_id' => $this->salleCulte->id, 'holder_id' => $this->ruth->id,
        ]),
        ...value($change),
    ])->sole();

    expect($movement->type)->toBe($type);
})->with([
    'département et service' => [fn () => ['department_id' => test()->jeunesse->id, 'service_id' => null], MovementType::Mixed],
    'service' => [fn () => ['service_id' => null], MovementType::Service],
    'local' => [fn () => ['room_id' => test()->polyvalente->id], MovementType::Room],
    'responsable' => [fn () => ['holder_id' => test()->david->id], MovementType::Holder],
    'site' => [fn () => ['site_id' => test()->moungali->id, 'room_id' => null], MovementType::Mixed],
    'retrait du responsable' => [fn () => ['holder_id' => null], MovementType::Holder],
]);

it('type département seul quand le bien n\'a pas de service', function () {
    $asset = Asset::factory()->create(['site_id' => $this->siege->id, 'department_id' => $this->louange->id]);

    $movement = app(TransferAssets::class)([$asset], ['site_id' => $this->siege->id, 'department_id' => $this->jeunesse->id, 'moved_on' => '2026-10-20'])->sole();

    expect($movement->type)->toBe(MovementType::Department);
});

it('type site seul quand le bien n\'a pas de local', function () {
    $asset = Asset::factory()->create(['site_id' => $this->siege->id, 'department_id' => $this->louange->id]);

    $movement = app(TransferAssets::class)([$asset], ['site_id' => $this->moungali->id, 'department_id' => $this->louange->id, 'moved_on' => '2026-10-20'])->sole();

    expect($movement->type)->toBe(MovementType::Site);
});

it('transfère plusieurs biens d\'un coup avec un mouvement par bien et un même lot (RG-23)', function () {
    $other = Asset::factory()->create(['site_id' => $this->moungali->id, 'department_id' => $this->louange->id]);

    $movements = app(TransferAssets::class)([$this->asset, $other], newAssignment(['service_id' => null, 'room_id' => null]));

    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('batch_uuid')->unique())->toHaveCount(1)
        ->and($movements->first()->batch_uuid)->not->toBeNull()
        ->and($movements->pluck('asset_id')->sort()->values()->all())->toBe([$this->asset->id, $other->id])
        ->and($other->fresh()->site_id)->toBe($this->siege->id)
        ->and($movements->firstWhere('asset_id', $other->id)->from_site_id)->toBe($this->moungali->id);
});

it('met à jour la version de la fiche (RG-86)', function () {
    $before = $this->asset->lock_version;

    app(TransferAssets::class)([$this->asset], newAssignment());

    expect($this->asset->fresh()->lock_version)->toBe($before + 1);
});

it('refuse le transfert quand la fiche a changé depuis l\'ouverture (RG-86)', function () {
    $opened = $this->asset->lock_version;
    $this->asset->update(['notes' => 'Modifié par un collègue']);

    expect(fn () => app(TransferAssets::class)([$this->asset], newAssignment(), [$this->asset->id => $opened]))
        ->toThrow(ConcurrentModificationException::class);

    expect(Movement::query()->count())->toBe(0);
});

it('accepte le transfert quand la version attendue est la bonne', function () {
    app(TransferAssets::class)([$this->asset], newAssignment(), [$this->asset->id => $this->asset->lock_version]);

    expect(Movement::query()->count())->toBe(1);
});

it('refuse un bien sorti du patrimoine', function () {
    $disposed = Asset::factory()->disposed()->create(['site_id' => $this->siege->id, 'department_id' => $this->louange->id]);

    expect(fn () => app(TransferAssets::class)([$disposed], newAssignment()))->toThrow(ValidationException::class);
    expect(Movement::query()->count())->toBe(0);
});

it('n\'enregistre rien si un seul bien du lot est refusé', function () {
    $disposed = Asset::factory()->disposed()->create(['site_id' => $this->siege->id, 'department_id' => $this->louange->id]);

    expect(fn () => app(TransferAssets::class)([$this->asset, $disposed], newAssignment()))->toThrow(ValidationException::class);

    expect(Movement::query()->count())->toBe(0)
        ->and($this->asset->fresh()->department_id)->toBe($this->louange->id);
});

it('refuse une affectation identique à l\'actuelle (au moins un élément doit changer)', function () {
    expect(fn () => app(TransferAssets::class)([$this->asset], newAssignment([
        'department_id' => $this->louange->id, 'service_id' => $this->chorale->id, 'room_id' => $this->salleCulte->id, 'holder_id' => $this->ruth->id,
    ])))->toThrow(ValidationException::class);

    expect(Movement::query()->count())->toBe(0);
});

it('refuse un transfert sans bien, sans site, sans département ou sans date', function (array $override) {
    expect(fn () => app(TransferAssets::class)($override['assets'] ?? [$this->asset], [...newAssignment(), ...value($override)]))
        ->toThrow(ValidationException::class);
})->with([
    'sans bien' => [['assets' => []]],
    'sans site' => [['site_id' => null]],
    'sans département' => [['department_id' => null]],
    'sans date' => [['moved_on' => null]],
    'date future' => [['moved_on' => '2999-01-01']],
]);

it('refuse une affectation incohérente : service d\'un autre département, local d\'un autre site', function (array $override) {
    expect(fn () => app(TransferAssets::class)([$this->asset], [...newAssignment(), ...value($override)]))
        ->toThrow(ValidationException::class);
})->with([
    'service' => [fn () => ['department_id' => test()->jeunesse->id, 'service_id' => test()->chorale->id]],
    'local' => [fn () => ['site_id' => test()->moungali->id, 'room_id' => test()->salleCulte->id]],
]);

it('refuse un site désactivé', function () {
    $closed = Site::factory()->create(['is_active' => false]);

    expect(fn () => app(TransferAssets::class)([$this->asset], newAssignment(['site_id' => $closed->id, 'room_id' => null])))
        ->toThrow(ValidationException::class);
});

it('refuse un bien hors du périmètre de l\'utilisateur (RG-81)', function () {
    $restricted = User::factory()->role(DefaultRoles::MANAGER)->create();
    $restricted->departments()->attach($this->jeunesse);
    $this->actingAs($restricted);

    expect(fn () => app(TransferAssets::class)([$this->asset], newAssignment()))->toThrow(ValidationException::class);

    expect(Movement::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->asset->fresh()->department_id)->toBe($this->louange->id);
});

it('refuse un utilisateur sans le droit de créer des mouvements', function (string $role) {
    $this->actingAs(User::factory()->role($role)->create());

    expect(fn () => app(TransferAssets::class)([$this->asset], newAssignment()))->toThrow(AuthorizationException::class);
})->with([DefaultRoles::VIEWER, DefaultRoles::ACCOUNTANT, DefaultRoles::DEPARTMENT_HEAD]);

it('trace chaque transfert au journal, avec avant et après (RG-70)', function () {
    app(TransferAssets::class)([$this->asset], newAssignment());

    $activity = Activity::query()->where('log_name', 'mouvements')->sole();

    expect($activity->description)->toBe('Transfert d\'un bien')
        ->and($activity->causer_id)->toBe($this->user->id)
        ->and($activity->subject_id)->toBe($this->asset->id)
        ->and($activity->properties->get('old'))->toMatchArray([
            'department_id' => 'Louange', 'service_id' => 'Chorale', 'room_id' => 'Salle de culte', 'holder_id' => 'Ruth Bouanga',
        ])
        ->and($activity->properties->get('attributes'))->toMatchArray([
            'department_id' => 'Jeunesse', 'service_id' => 'Culte des jeunes', 'room_id' => 'Salle polyvalente', 'holder_id' => 'David Massamba',
        ]);
});

it('rattache le justificatif aux biens transférés', function () {
    Storage::fake('private');
    $type = DocumentType::factory()->create(['label' => 'Document de transfert']);
    $other = Asset::factory()->create(['site_id' => $this->siege->id, 'department_id' => $this->louange->id]);

    app(TransferAssets::class)(
        [$this->asset, $other],
        newAssignment(),
        files: [UploadedFile::fake()->create('bon.pdf', 20, 'application/pdf')],
        documentType: $type,
    );

    $document = Document::query()->sole();

    expect($document->original_name)->toBe('bon.pdf')
        ->and($document->assets()->pluck('assets.id')->sort()->values()->all())->toBe([$this->asset->id, $other->id]);
});

it('crée l\'affectation initiale comme premier mouvement à la création (RG-22)', function () {
    $category = AssetCategory::factory()->create(['prefix' => 'INF']);
    $condition = AssetCondition::factory()->create();

    $asset = app(CreateAssetAction::class)([
        'designation' => 'Ordinateur portable',
        'asset_category_id' => $category->id,
        'acquired_on' => '2026-03-10',
        'acquisition_cost' => 50000,
        'site_id' => $this->siege->id,
        'department_id' => $this->louange->id,
        'service_id' => $this->chorale->id,
        'room_id' => $this->salleCulte->id,
        'holder_id' => $this->ruth->id,
        'asset_condition_id' => $condition->id,
        'status' => 'in_service',
    ]);

    $movement = Movement::query()->where('asset_id', $asset->id)->sole();

    expect($movement->type)->toBe(MovementType::Initial)
        ->and($movement->from_site_id)->toBeNull()
        ->and($movement->from_department_id)->toBeNull()
        ->and($movement->to_site_id)->toBe($this->siege->id)
        ->and($movement->to_department_id)->toBe($this->louange->id)
        ->and($movement->to_service_id)->toBe($this->chorale->id)
        ->and($movement->to_room_id)->toBe($this->salleCulte->id)
        ->and($movement->to_holder_id)->toBe($this->ruth->id)
        ->and($movement->moved_on->toDateString())->toBe('2026-03-10');
});

it('n\'enregistre pas de bien si l\'affectation initiale échoue (même transaction)', function () {
    $category = AssetCategory::factory()->create(['prefix' => 'INF']);
    $condition = AssetCondition::factory()->create();

    Movement::creating(fn () => throw new RuntimeException('échec simulé'));

    expect(fn () => app(CreateAssetAction::class)([
        'designation' => 'Imprimante',
        'asset_category_id' => $category->id,
        'acquired_on' => '2026-03-10',
        'acquisition_cost' => 50000,
        'site_id' => $this->siege->id,
        'department_id' => $this->louange->id,
        'asset_condition_id' => $condition->id,
        'status' => 'in_service',
    ]))->toThrow(RuntimeException::class);

    expect(Asset::query()->where('designation', 'Imprimante')->exists())->toBeFalse();
});

it('laisse un mouvement quand l\'affectation est modifiée depuis la fiche (RG-21)', function () {
    app(UpdateAsset::class)($this->asset, ['room_id' => $this->polyvalente->id, 'designation' => $this->asset->designation]);

    $movement = Movement::query()->where('asset_id', $this->asset->id)->sole();

    expect($movement->type)->toBe(MovementType::Room)
        ->and($movement->from_room_id)->toBe($this->salleCulte->id)
        ->and($movement->to_room_id)->toBe($this->polyvalente->id);
});

it('ne crée aucun mouvement quand la modification de la fiche ne touche pas l\'affectation', function () {
    app(UpdateAsset::class)($this->asset, ['notes' => 'Une remarque']);

    expect(Movement::query()->count())->toBe(0);
});

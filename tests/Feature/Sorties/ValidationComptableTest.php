<?php

use App\Actions\Disposals\ValidateDisposal;
use App\Actions\FiscalYears\CloseFiscalYear;
use App\Enums\DisposalAccountingStatus;
use App\Enums\FiscalYearStatus;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Disposal;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use App\Services\FiscalYearClosing\AttentionPoint;
use App\Services\FiscalYearClosing\FiscalYearAttentionPoints;
use App\Support\DefaultRoles;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    disposalFiscalYears();
    $this->asset = a5Asset();
    $this->disposal = recordDisposal($this->asset);
});

afterEach(fn () => Carbon::setTestNow());

it('laisse l\'administrateur et le comptable valider, une seule fois, avec trace (RG-54, RG-70)', function (string $role) {
    $user = User::factory()->role($role)->create();

    expect($user->can('validate', $this->disposal))->toBeTrue();

    app(ValidateDisposal::class)($this->disposal, $user);
    $disposal = $this->disposal->fresh();

    expect($disposal->accounting_status)->toBe(DisposalAccountingStatus::Validated)
        ->and($disposal->accounting_validated_by)->toBe($user->id)
        ->and($disposal->accounting_validated_at)->not->toBeNull()
        ->and($user->can('validate', $disposal))->toBeFalse()
        ->and(fn () => app(ValidateDisposal::class)($disposal, $user))->toThrow(ValidationException::class, 'déjà validée');

    $entry = Activity::query()->where('log_name', 'sorties')->where('event', 'disposal_validated')->sole();

    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->properties['old'])->toBe(['accounting_status' => 'À valider'])
        ->and($entry->properties['attributes']['accounting_status'])->toBe('Validée');
})->with([DefaultRoles::ADMIN, DefaultRoles::ACCOUNTANT]);

it('refuse la validation aux autres rôles, même en appelant l\'action directement (RG-83)', function (string $role) {
    $user = User::factory()->role($role)->create();

    expect($user->can('validate', $this->disposal))->toBeFalse()
        ->and(fn () => app(ValidateDisposal::class)($this->disposal, $user))->toThrow(AuthorizationException::class)
        ->and($this->disposal->fresh()->accounting_status)->toBe(DisposalAccountingStatus::Pending);
})->with([DefaultRoles::MANAGER, DefaultRoles::VIEWER, DefaultRoles::DEPARTMENT_HEAD]);

it('refuse un rôle à qui la matrice donnerait le droit de valider (règle en dur, RG-83)', function () {
    (new RolesSeeder)->run();
    $role = Role::query()->create(['name' => 'Trésorier', 'guard_name' => 'web']);
    $role->givePermissionTo(['disposals.view', 'disposals.validate']);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('disposals.validate'))->toBeTrue()
        ->and($user->can('validate', $this->disposal))->toBeFalse()
        ->and(fn () => app(ValidateDisposal::class)($this->disposal, $user))->toThrow(AuthorizationException::class);
});

it('refuse un comptable dont la matrice a retiré le droit de valider', function () {
    $accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    Role::findByName(DefaultRoles::ACCOUNTANT)->revokePermissionTo('disposals.validate');

    expect(fn () => app(ValidateDisposal::class)($this->disposal, $accountant->fresh()))->toThrow(AuthorizationException::class);
});

it('refuse un compte désactivé', function () {
    $accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create(['is_active' => false]);

    expect(fn () => app(ValidateDisposal::class)($this->disposal, $accountant))->toThrow(AuthorizationException::class);
});

it('ne laisse pas valider une sortie hors du périmètre (RG-81)', function () {
    $accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $accountant->departments()->attach(Department::factory()->create());
    $this->actingAs($accountant);

    expect($accountant->can('view', $this->disposal))->toBeFalse()
        ->and(Disposal::query()->count())->toBe(0)
        ->and(fn () => app(ValidateDisposal::class)($this->disposal, $accountant))->toThrow(ValidationException::class, 'hors de votre périmètre');
});

it('ne touche ni au plan ni aux valeurs figées', function () {
    $lines = lineSnapshot($this->asset, lockedOnly: false);
    $values = $this->disposal->only(['original_value', 'accumulated_depreciation', 'net_book_value']);

    app(ValidateDisposal::class)($this->disposal, User::factory()->role(DefaultRoles::ACCOUNTANT)->create());

    expect(lineSnapshot($this->asset, lockedOnly: false))->toBe($lines)
        ->and($this->disposal->fresh()->only(['original_value', 'accumulated_depreciation', 'net_book_value']))->toBe($values);
});

it('signale les sorties non validées avant la clôture de l\'exercice (RG-42)', function () {
    $other = recordDisposal(createDepreciableAsset(), ['disposed_on' => '2026-03-02']);
    $points = collect(app(FiscalYearAttentionPoints::class)->for(FiscalYear::query()->where('year', 2026)->sole()));
    $point = $points->firstWhere('key', 'disposals');

    expect($point->severity)->toBe(AttentionPoint::WARNING)
        ->and($point->label)->toContain('2 sorties non validées')
        ->and($point->label)->toContain($this->disposal->reference)
        ->and($point->label)->toContain($other->reference);

    $accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    app(ValidateDisposal::class)($this->disposal, $accountant);
    app(ValidateDisposal::class)($other, $accountant);

    $point = collect(app(FiscalYearAttentionPoints::class)->for(FiscalYear::query()->where('year', 2026)->sole()))->firstWhere('key', 'disposals');

    expect($point->severity)->toBe(AttentionPoint::OK);
});

it('n\'empêche pas la clôture, qui fige l\'exercice de la sortie sans changer ses valeurs (RG-42, RG-36)', function () {
    $accountant = User::factory()->role(DefaultRoles::ACCOUNTANT)->create();
    $values = $this->disposal->only(['original_value', 'accumulated_depreciation', 'net_book_value']);

    expect(fn () => app(CloseFiscalYear::class)(FiscalYear::query()->where('year', 2026)->sole(), $accountant, today: CarbonImmutable::parse('2026-12-31')))
        ->toThrow(ValidationException::class, 'Confirmez');

    app(CloseFiscalYear::class)(FiscalYear::query()->where('year', 2026)->sole(), $accountant, acknowledged: true, today: CarbonImmutable::parse('2026-12-31'));

    $closed = FiscalYear::query()->where('year', 2026)->sole();
    $line2026 = currentSchedule($this->asset)->lines->firstWhere('fiscal_year', 2026);

    expect($closed->status)->toBe(FiscalYearStatus::Closed)
        ->and(collect($closed->closing_notes['attention_points'])->firstWhere('key', 'disposals')['severity'])->toBe(AttentionPoint::WARNING)
        ->and($line2026->is_locked)->toBeTrue()
        ->and($line2026->amount)->toBe(493333)
        ->and($this->disposal->fresh()->only(['original_value', 'accumulated_depreciation', 'net_book_value']))->toBe($values);

    // Validation après la clôture : possible, et rien d'autre ne bouge (RG-36).
    $lines = lineSnapshot($this->asset, lockedOnly: false);
    app(ValidateDisposal::class)($this->disposal, $accountant);

    expect(lineSnapshot($this->asset, lockedOnly: false))->toBe($lines);
});

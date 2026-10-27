<?php

use App\Actions\Users\SyncRolePermissions;
use App\Enums\PermissionModule;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Activity;
use App\Models\Role;
use App\Models\User;
use App\Support\DefaultRoles;
use Database\Seeders\RolesSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('livre les cinq rôles de RG-80', function () {
    expect(Role::query()->orderBy('id')->pluck('name')->all())->toBe([
        'Administrateur', 'Gestionnaire du patrimoine', 'Comptable', 'Responsable de département', 'Consultation',
    ]);
});

it('applique la matrice par défaut de la maquette', function () {
    $admin = Role::findByName(DefaultRoles::ADMIN);
    $manager = Role::findByName(DefaultRoles::MANAGER);
    $accountant = Role::findByName(DefaultRoles::ACCOUNTANT);
    $head = Role::findByName(DefaultRoles::DEPARTMENT_HEAD);
    $viewer = Role::findByName(DefaultRoles::VIEWER);

    expect($admin->permissions()->count())->toBe(96)
        ->and($manager->hasPermissionTo('assets.create'))->toBeTrue()
        ->and($manager->hasPermissionTo('structure.view'))->toBeTrue()
        ->and($manager->hasPermissionTo('structure.update'))->toBeFalse()
        ->and($manager->hasPermissionTo('users.view'))->toBeFalse()
        ->and($accountant->hasPermissionTo('depreciation.validate'))->toBeTrue()
        ->and($accountant->hasPermissionTo('disposals.validate'))->toBeTrue()
        ->and($head->hasPermissionTo('asset_reports.create'))->toBeTrue()
        ->and($head->hasPermissionTo('assets.update'))->toBeFalse()
        ->and($viewer->hasPermissionTo('assets.view'))->toBeTrue()
        ->and($viewer->hasPermissionTo('assets.create'))->toBeFalse()
        ->and($viewer->hasPermissionTo('users.view'))->toBeFalse();
});

it('ne réécrit pas la matrice d\'un rôle modifié par l\'administrateur', function () {
    Role::findByName(DefaultRoles::MANAGER)->givePermissionTo('users.view');

    $this->seed(RolesSeeder::class);

    expect(Role::findByName(DefaultRoles::MANAGER)->hasPermissionTo('users.view'))->toBeTrue();
});

it('modifie la matrice d\'un rôle et trace le changement', function () {
    $this->actingAs(User::factory()->admin()->create());
    $role = Role::findByName(DefaultRoles::VIEWER);

    livewire(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([RoleResource::field(PermissionModule::Assets) => ['assets.view', 'assets.export']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->hasPermissionTo('assets.export'))->toBeTrue();

    $activity = Activity::query()->where('log_name', 'roles')->latest('id')->first();
    expect($activity->description)->toContain('Consultation')
        ->and($activity->properties->get('attributes')['ajoutés'])->toBe(['assets.export']);
});

it('garde tous les droits au rôle Administrateur', function () {
    $role = Role::findByName(DefaultRoles::ADMIN);

    app(SyncRolePermissions::class)($role, []);

    expect($role->refresh()->permissions()->count())->toBe(96);
});

it('ne supprime ni un rôle livré ni un rôle attribué', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $custom = Role::query()->create(['name' => 'Trésorier', 'guard_name' => 'web']);
    $used = Role::query()->create(['name' => 'Auditeur', 'guard_name' => 'web']);
    User::factory()->create()->assignRole($used);

    livewire(ListRoles::class)
        ->assertActionHidden(TestAction::make(DeleteAction::class)->table(Role::findByName(DefaultRoles::MANAGER)))
        ->assertActionHidden(TestAction::make(DeleteAction::class)->table($used))
        ->callAction(TestAction::make(DeleteAction::class)->table($custom));

    expect(Role::query()->where('name', 'Trésorier')->exists())->toBeFalse();
});

<?php

use App\Models\Department;
use App\Models\Role;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;
use Database\Seeders\RolesSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * @return list<string>
 */
function navigationItems(): array
{
    return collect(Filament::getNavigation())
        ->flatMap(fn (NavigationGroup $group) => $group->getItems())
        ->map(fn ($item) => (string) $item->getLabel())
        ->values()
        ->all();
}

it('réduit le menu selon les droits (RG-80, E42)', function (string $role, array $visible, array $hidden) {
    $this->actingAs(User::factory()->role($role)->create())->get('/admin')->assertOk();

    expect(navigationItems())->toContain(...$visible);

    foreach ($hidden as $label) {
        expect(navigationItems())->not->toContain($label);
    }
})->with([
    'responsable de département' => [DefaultRoles::DEPARTMENT_HEAD, ['Tableau de bord'], ['Sites', 'Utilisateurs', 'Journal des opérations']],
    'gestionnaire' => [DefaultRoles::MANAGER, ['Sites', 'Journal des opérations'], ['Utilisateurs', 'Rôles et permissions']],
    'administrateur' => [DefaultRoles::ADMIN, ['Sites', 'Utilisateurs', 'Rôles et permissions', 'Journal des opérations'], []],
]);

it('refuse par l\'URL directe ce que le rôle ne permet pas', function (string $role, string $url, int $status) {
    $this->actingAs(User::factory()->role($role)->create())
        ->get($url)
        ->assertStatus($status);
})->with([
    'gestionnaire, création de site' => [DefaultRoles::MANAGER, '/admin/sites/create', 403],
    'gestionnaire, liste des sites' => [DefaultRoles::MANAGER, '/admin/sites', 200],
    'gestionnaire, utilisateurs' => [DefaultRoles::MANAGER, '/admin/utilisateurs', 403],
    'responsable, locaux' => [DefaultRoles::DEPARTMENT_HEAD, '/admin/locaux', 403],
    'responsable, journal' => [DefaultRoles::DEPARTMENT_HEAD, '/admin/journal', 403],
    'consultation, rôles' => [DefaultRoles::VIEWER, '/admin/roles', 403],
    'comptable, journal' => [DefaultRoles::ACCOUNTANT, '/admin/journal', 200],
]);

it('limite les données au périmètre de sites de l\'utilisateur (RG-81)', function () {
    $siege = Site::factory()->create();
    $moungali = Site::factory()->create();
    $roomSiege = Room::factory()->for($siege)->create();
    $roomMoungali = Room::factory()->for($moungali)->create();

    $user = User::factory()->role(DefaultRoles::MANAGER)->create();
    $user->sites()->attach($moungali);
    $this->actingAs($user);

    expect(Room::query()->pluck('id')->all())->toBe([$roomMoungali->id])
        ->and(Site::query()->pluck('id')->all())->toBe([$moungali->id])
        ->and(Room::query()->find($roomSiege->id))->toBeNull();

    $this->get('/admin/locaux')->assertOk()->assertSee($roomMoungali->code)->assertDontSee($roomSiege->code);
});

it('refuse l\'accès par l\'URL directe à un élément hors périmètre, même avec le droit de modifier', function () {
    $siege = Site::factory()->create();
    $moungali = Site::factory()->create();
    $roomSiege = Room::factory()->for($siege)->create();
    $roomMoungali = Room::factory()->for($moungali)->create();

    $this->seed(RolesSeeder::class);
    $role = Role::query()->create(['name' => 'Gestionnaire de structure', 'guard_name' => 'web']);
    $role->givePermissionTo(['structure.view', 'structure.update']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->sites()->attach($moungali);

    $this->actingAs($user)->get("/admin/locaux/{$roomMoungali->id}/edit")->assertOk();
    $this->actingAs($user)->get("/admin/locaux/{$roomSiege->id}/edit")->assertNotFound();
});

it('montre au responsable de département les seuls locaux de son département et les locaux communs', function () {
    $site = Site::factory()->create();
    $louange = Department::factory()->create();
    $jeunesse = Department::factory()->create();
    $repetition = Room::factory()->for($site)->for($louange)->create();
    $culte = Room::factory()->for($site)->create(['department_id' => null]);
    $sport = Room::factory()->for($site)->for($jeunesse)->create();

    $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $head->departments()->attach($louange);
    $this->actingAs($head);

    expect(Room::query()->pluck('id')->sort()->values()->all())->toBe(collect([$repetition->id, $culte->id])->sort()->values()->all())
        ->and(Department::query()->pluck('id')->all())->toBe([$louange->id])
        ->and(Room::query()->find($sport->id))->toBeNull();
});

it('ne filtre rien pour un périmètre vide (tous les sites, tous les départements)', function () {
    Room::factory()->count(3)->create();

    $this->actingAs(User::factory()->admin()->create());

    expect(Room::query()->count())->toBe(3);
});

it('ne filtre rien hors connexion (console, seeders)', function () {
    Room::factory()->count(2)->create();

    expect(Room::query()->count())->toBe(2);
});

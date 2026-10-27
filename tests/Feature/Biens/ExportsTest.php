<?php

use App\Exports\AssetsExport;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\AssetListReport;
use App\Services\Settings;
use App\Support\DefaultRoles;
use Database\Seeders\RolesSeeder;
use Filament\Facades\Filament;
use Maatwebsite\Excel\Facades\Excel;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo('2026-10-27 09:30');

    $this->louange = Department::factory()->create(['name' => 'Louange']);
    $this->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
    $this->micro = Asset::factory()->create(['designation' => 'Microphone', 'brand' => 'Shure', 'model' => 'SLX24', 'department_id' => $this->louange->id, 'number' => 'PAT-000501', 'acquisition_cost' => 200000]);
    $this->enceinte = Asset::factory()->create(['designation' => 'Enceinte', 'department_id' => $this->louange->id, 'number' => 'PAT-000502', 'acquisition_cost' => 600000]);
    $this->ballon = Asset::factory()->create(['designation' => 'Ballon', 'department_id' => $this->jeunesse->id, 'number' => 'PAT-000503', 'acquisition_cost' => 15000]);
});

it('télécharge l\'export Excel de la liste, nommé biens-AAAA-MM-JJ.xlsx', function () {
    Excel::fake();
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());

    livewire(ListAssets::class)->callAction('exportExcel')->assertHasNoActionErrors();

    Excel::assertDownloaded('biens-2026-10-27.xlsx', function (AssetsExport $export) {
        $rows = $export->array();

        expect($export->headings())->toBe(AssetListReport::HEADINGS)
            ->and($rows)->toHaveCount(4)
            ->and(collect($rows)->pluck(0)->all())->toContain('PAT-000501', 'PAT-000502', 'PAT-000503');

        return true;
    });
});

it('met les montants en nombres entiers et une ligne de total dans l\'Excel', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());

    $data = app(AssetListReport::class)->build(Asset::query()->orderBy('number'), ['Statut : Actifs']);
    $rows = (new AssetsExport($data))->array();
    $first = $rows[0];
    $total = $rows[array_key_last($rows)];

    expect($first[11])->toBeInt()->toBe(200000)
        ->and($first[2])->toBe('Shure SLX24')
        ->and($first[10])->toBe($this->micro->acquired_on->format('d/m/Y'))
        ->and($total[0])->toBe('Total (3 biens)')
        ->and($total[11])->toBeInt()->toBe(815000);
});

it('exporte le PDF de la liste en A4 paysage sans ressource distante', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());
    app(Settings::class)->set(Settings::CHURCH_NAME, 'Église Évangélique Exemple');
    app(Settings::class)->set(Settings::CHURCH_ACRONYM, 'EEE');

    livewire(ListAssets::class)->callAction('exportPdf')->assertFileDownloaded('biens-2026-10-27.pdf');

    $data = app(AssetListReport::class)->build(Asset::query()->orderBy('number'), ['Département : Louange']);
    $html = view('pdf.assets-list', ['data' => $data])->render();

    expect($html)
        ->toContain('Église Évangélique Exemple')
        ->toContain('(EEE)')
        ->toContain('27/10/2026')
        ->toContain('Département : Louange')
        ->toContain('PAT-000501')
        ->toContain('3 biens')
        ->not->toContain('http');
});

it('n\'exporte que les biens filtrés à l\'écran', function () {
    Excel::fake();
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());

    livewire(ListAssets::class)
        ->filterTable('department_id', $this->louange->id)
        ->callAction('exportExcel');

    Excel::assertDownloaded('biens-2026-10-27.xlsx', function (AssetsExport $export) {
        $numbers = collect($export->data->rows)->pluck(0)->all();

        expect($numbers)->toEqualCanonicalizing(['PAT-000501', 'PAT-000502'])
            ->and($export->data->total)->toBe(800000)
            ->and(implode(' ', $export->data->filters))->toContain('Louange');

        return true;
    });
});

it('respecte la recherche et le tri de l\'écran', function () {
    Excel::fake();
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());

    livewire(ListAssets::class)
        ->searchTable('Shure')
        ->callAction('exportExcel');

    Excel::assertDownloaded('biens-2026-10-27.xlsx', fn (AssetsExport $export) => collect($export->data->rows)->pluck(0)->all() === ['PAT-000501']);

    Excel::fake();

    livewire(ListAssets::class)
        ->sortTable('acquisition_cost', 'desc')
        ->callAction('exportExcel');

    Excel::assertDownloaded('biens-2026-10-27.xlsx', fn (AssetsExport $export) => collect($export->data->rows)->pluck(0)->all() === ['PAT-000502', 'PAT-000501', 'PAT-000503']);
});

it('limite l\'export au périmètre de l\'utilisateur (RG-81)', function () {
    Excel::fake();
    $this->seed(RolesSeeder::class);
    $role = Role::query()->create(['name' => 'Lecteur exportateur', 'guard_name' => 'web']);
    $role->givePermissionTo(['assets.view', 'assets.export']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->departments()->attach($this->jeunesse);
    $this->actingAs($user);

    livewire(ListAssets::class)->callAction('exportExcel');

    Excel::assertDownloaded('biens-2026-10-27.xlsx', function (AssetsExport $export) {
        expect(collect($export->data->rows)->pluck(0)->all())->toBe(['PAT-000503'])
            ->and($export->data->total)->toBe(15000);

        return true;
    });
});

it('cache les exports sans le droit assets.export', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create());

    livewire(ListAssets::class)
        ->assertActionHidden('exportExcel')
        ->assertActionHidden('exportPdf');
});

it('montre les exports au gestionnaire', function () {
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());

    livewire(ListAssets::class)
        ->assertActionVisible('exportExcel')
        ->assertActionVisible('exportPdf');
});

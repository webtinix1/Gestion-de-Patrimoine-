<?php

use App\Filament\Pages\SearchByCode;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->role(DefaultRoles::MANAGER)->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('ouvre directement la fiche du bien lu par la douchette (RG-05)', function () {
    $asset = Asset::factory()->create(['number' => 'PAT-000087']);

    livewire(SearchByCode::class)
        ->fillForm(['code' => 'PAT-000087'])
        ->call('search')
        ->assertRedirect(AssetResource::getUrl('view', ['record' => $asset]));
});

it('accepte un code saisi en minuscules ou avec des espaces', function () {
    $asset = Asset::factory()->create(['number' => 'PAT-000412']);

    livewire(SearchByCode::class)
        ->fillForm(['code' => '  pat-000412 '])
        ->call('search')
        ->assertRedirect(AssetResource::getUrl('view', ['record' => $asset]));
});

it('signale un code inconnu et propose de créer la fiche', function () {
    livewire(SearchByCode::class)
        ->fillForm(['code' => 'PAT-009991'])
        ->call('search')
        ->assertNoRedirect()
        ->assertSee('Aucun bien avec ce code')
        ->assertSee('Créer la fiche de ce bien');
});

it('garde l\'historique des derniers codes lus sur le poste', function () {
    Asset::factory()->create(['number' => 'PAT-000087', 'designation' => 'Console de mixage']);

    livewire(SearchByCode::class)
        ->fillForm(['code' => 'PAT-009991'])->call('search')
        ->fillForm(['code' => 'PAT-000087'])->call('search');

    expect(collect(session(SearchByCode::HISTORY_KEY))->pluck('code')->all())->toBe(['PAT-000087', 'PAT-009991']);
});

it('place le curseur dans le champ à l\'ouverture', function () {
    $this->get('/admin/recherche-par-code')->assertOk()->assertSee('autofocus', false);
});

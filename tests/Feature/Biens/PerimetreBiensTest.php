<?php

use App\Filament\Pages\SearchByCode;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Models\Asset;
use App\Models\Department;
use App\Models\User;
use App\Support\DefaultRoles;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->louange = Department::factory()->create(['name' => 'Louange']);
    $this->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
    $this->ownAsset = Asset::factory()->create(['department_id' => $this->louange->id, 'number' => 'PAT-000502']);
    $this->otherAsset = Asset::factory()->create(['department_id' => $this->jeunesse->id, 'number' => 'PAT-000087']);

    $this->head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $this->head->departments()->attach($this->louange);
    $this->actingAs($this->head);
});

it('ne montre au responsable de département que les biens de son département (RG-81, RG-82)', function () {
    livewire(ListAssets::class)
        ->assertCanSeeTableRecords([$this->ownAsset])
        ->assertCanNotSeeTableRecords([$this->otherAsset]);
});

it('refuse un bien hors périmètre même par l\'URL directe', function () {
    $this->get("/admin/biens/{$this->ownAsset->id}")->assertOk();
    $this->get("/admin/biens/{$this->otherAsset->id}")->assertNotFound();
});

it('ne trouve pas un bien hors périmètre par la recherche par code', function () {
    livewire(SearchByCode::class)
        ->fillForm(['code' => 'PAT-000087'])
        ->call('search')
        ->assertSet('unknownCode', 'PAT-000087')
        ->assertNoRedirect();
});

it('ne laisse pas le responsable modifier une fiche de son département', function () {
    $this->get("/admin/biens/{$this->ownAsset->id}/edit")->assertForbidden();
});

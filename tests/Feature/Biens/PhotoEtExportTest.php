<?php

use App\Exports\AssetsExport;
use App\Models\Asset;
use App\Models\Department;
use App\Models\User;
use App\Support\AssetListData;
use App\Support\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

beforeEach(function () {
    Storage::fake('local');

    $this->louange = Department::factory()->create();
    $this->jeunesse = Department::factory()->create();
    $this->own = Asset::factory()->create(['department_id' => $this->louange->id, 'photo_path' => 'biens/photos/a.jpg']);
    $this->other = Asset::factory()->create(['department_id' => $this->jeunesse->id, 'photo_path' => 'biens/photos/b.jpg']);
    Storage::disk('local')->put('biens/photos/a.jpg', UploadedFile::fake()->image('a.jpg')->getContent());
    Storage::disk('local')->put('biens/photos/b.jpg', UploadedFile::fake()->image('b.jpg')->getContent());
});

it('sert la photo d\'un bien par une route contrôlée (RG-66)', function () {
    $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
    $head->departments()->attach($this->louange);

    $this->actingAs($head)->get(route('filament.admin.assets.photo', ['asset' => $this->own->id]))->assertOk();
    $this->actingAs($head)->get(route('filament.admin.assets.photo', ['asset' => $this->other->id]))->assertNotFound();
});

it('refuse la photo sans connexion', function () {
    $this->get(route('filament.admin.assets.photo', ['asset' => $this->own->id]))->assertRedirect('/admin/login');
});

it('écrit les textes de l\'export Excel comme du texte, jamais comme une formule', function () {
    $export = new AssetsExport(new AssetListData(
        headings: ['Désignation'], rows: [], count: 0, total: 0, filters: [],
        churchName: 'Église', churchAcronym: 'É', editedAt: now(),
    ));
    $cell = (new Spreadsheet)->getActiveSheet()->getCell('A1');

    $export->bindValue($cell, '=HYPERLINK("http://exemple.test","clic")');

    expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($cell->getValue())->toBe('=HYPERLINK("http://exemple.test","clic")');
});

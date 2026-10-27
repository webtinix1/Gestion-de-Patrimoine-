<?php

use App\Enums\RoomType;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Models\Department;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('crée un local rattaché à un site, avec un département facultatif', function () {
    $site = Site::factory()->create();
    $department = Department::factory()->create();

    livewire(CreateRoom::class)
        ->fillForm([
            'name' => 'Salle de répétition',
            'code' => 'sie-rep',
            'site_id' => $site->id,
            'type' => RoomType::MeetingRoom->value,
            'department_id' => $department->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Room::query()->sole())
        ->code->toBe('SIE-REP')
        ->type->toBe(RoomType::MeetingRoom)
        ->site_id->toBe($site->id)
        ->department_id->toBe($department->id);
});

it('exige un site actif', function () {
    $inactive = Site::factory()->inactive()->create();

    livewire(CreateRoom::class)
        ->fillForm(['name' => 'Bureau', 'code' => 'X-BUR', 'site_id' => null])
        ->call('create')
        ->assertHasFormErrors(['site_id' => 'required']);

    livewire(CreateRoom::class)
        ->fillForm(['name' => 'Bureau', 'code' => 'X-BUR', 'site_id' => $inactive->id])
        ->call('create')
        ->assertHasFormErrors(['site_id']);
});

it('liste les locaux et les filtre par site', function () {
    $siege = Site::factory()->create();
    $annexe = Site::factory()->create();
    $culte = Room::factory()->for($siege)->create();
    $magasin = Room::factory()->for($annexe)->create();

    livewire(ListRooms::class)
        ->assertCanSeeTableRecords([$culte, $magasin])
        ->filterTable('site_id', $siege->id)
        ->assertCanSeeTableRecords([$culte])
        ->assertCanNotSeeTableRecords([$magasin]);
});

it('libelle les types de local en français', function () {
    expect(collect(RoomType::cases())->map->getLabel()->all())->toBe([
        'Bureau', 'Salle de culte', 'Salle de réunion', 'Salle informatique',
        'Magasin', 'Salle de formation', 'Local technique', 'Autre',
    ]);
});

<?php

use App\Actions\Movements\TransferAssets;
use App\Filament\Resources\Activities\ActivityResource;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Site;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo('2026-10-27 09:30');
    $this->actingAs(User::factory()->admin()->create());
});

function texteJournal(Activity $activity): string
{
    return html_entity_decode(strip_tags(str_replace('</div>', "\n", ActivityResource::changesHtml($activity)->toHtml())));
}

it('affiche la modification d\'une fiche en français : noms liés, libellés, dates locales', function () {
    $ancien = Site::factory()->create(['name' => 'Siège']);
    $nouveau = Site::factory()->create(['name' => 'Paroisse Moungali']);
    $fournisseur = Supplier::factory()->create(['name' => 'Électro Congo']);
    $asset = Asset::factory()->create(['site_id' => $ancien->id, 'brand' => 'Shure', 'status' => 'in_service', 'funding_source' => 'own_funds'])->fresh();

    $asset->update([
        'site_id' => $nouveau->id, 'brand' => 'Yamaha', 'supplier_id' => $fournisseur->id,
        'status' => 'in_maintenance', 'funding_source' => 'grant', 'acquired_on' => '2026-10-20',
    ]);

    $texte = texteJournal(Activity::query()->where('log_name', 'biens')->where('event', 'updated')->latest('id')->firstOrFail());

    expect($texte)
        ->toContain('site : Siège → Paroisse Moungali')
        ->toContain('marque : Shure → Yamaha')
        ->toContain('fournisseur : — → Électro Congo')
        ->toContain('Subvention')
        ->toContain('20/10/2026')
        ->not->toContain('asset category id')
        ->not->toContain('T23:00:00')
        ->not->toContain('_id');
});

it('convertit une date ISO UTC en date locale et garde « n° X » si l\'objet lié a disparu', function () {
    $activity = new Activity(['subject_type' => (new Asset)->getMorphClass()]);

    expect(ActivityResource::display($activity, 'acquired_on', '2026-10-19T23:00:00.000000Z'))->toBe('20/10/2026')
        ->and(ActivityResource::display($activity, 'site_id', 99999))->toBe('n° 99999')
        ->and(ActivityResource::display($activity, 'status', 'valeur inconnue'))->toBe('valeur inconnue')
        ->and(ActivityResource::display($activity, 'reason', 'Réforme'))->toBe('Réforme');
});

it('ne laisse pas de doublon « Modification d\'une fiche » pour un transfert, et garde l\'avant/après', function () {
    $siege = Site::factory()->create(['name' => 'Siège']);
    $moungali = Site::factory()->create(['name' => 'Paroisse Moungali']);
    $louange = Department::factory()->create(['name' => 'Louange']);
    $asset = Asset::factory()->create(['site_id' => $siege->id, 'department_id' => $louange->id, 'service_id' => null, 'room_id' => null, 'holder_id' => null])->fresh();
    $avant = Activity::query()->where('log_name', 'biens')->where('event', 'updated')->count();

    app(TransferAssets::class)([$asset], ['site_id' => $moungali->id, 'department_id' => $louange->id, 'moved_on' => '2026-10-20']);

    $mouvement = Activity::query()->where('log_name', 'mouvements')->sole();

    expect(Activity::query()->where('log_name', 'biens')->where('event', 'updated')->count())->toBe($avant)
        ->and($mouvement->properties['old'])->toBe(['site_id' => 'Siège'])
        ->and(texteJournal($mouvement))->toContain('site : Siège → Paroisse Moungali')
        ->and($asset->fresh()->site_id)->toBe($moungali->id);
});

<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('présente le groupe Structure avec les entrées de docs/04 dans l\'ordre', function () {
    $this->get('/admin')->assertOk();

    /** @var NavigationGroup $group */
    $group = collect(Filament::getNavigation())->first(fn (NavigationGroup $group) => $group->getLabel() === 'Structure');

    expect($group)->not->toBeNull()
        ->and(collect($group->getItems())->map(fn (NavigationItem $item) => $item->getLabel())->values()->all())
        ->toBe(['Sites', 'Départements et services', 'Locaux', 'Catégories et référentiels']);
});

it('ouvre chaque écran du lot 1', function (string $url, string $title) {
    $this->get($url)->assertOk()->assertSee($title);
})->with([
    ['/admin/sites', 'Sites'],
    ['/admin/sites/create', 'Créer site'],
    ['/admin/departements', 'Départements'],
    ['/admin/departements/create', 'Créer département'],
    ['/admin/locaux', 'Locaux'],
    ['/admin/locaux/create', 'Créer local'],
    ['/admin/referentiels/categories', 'Catégories de biens'],
    ['/admin/referentiels/categories/create', 'Créer catégorie'],
    ['/admin/referentiels/etats', 'États'],
    ['/admin/referentiels/fournisseurs', 'Fournisseurs'],
    ['/admin/referentiels/types-documents', 'Types de documents'],
    ['/admin/referentiels/statuts-motifs', 'Statuts, motifs et financements'],
]);

it('affiche les statuts, motifs de sortie et financements en lecture seule', function () {
    $this->get('/admin/referentiels/statuts-motifs')
        ->assertOk()
        ->assertSeeInOrder(['En service', 'En stock', 'En maintenance', 'Emprunté', 'Hors service', 'Cédé', 'Réformé'])
        ->assertSee('Intervention de maintenance')
        ->assertSeeInOrder(['Cession', 'Don', 'Réforme', 'Destruction', 'Perte', 'Vol', 'Autre motif'])
        ->assertSeeInOrder(['Fonds propres', 'Don affecté', 'Subvention']);
});

it('refuse l\'accès aux référentiels sans connexion', function () {
    auth()->logout();

    $this->get('/admin/sites')->assertRedirect('/admin/login');
});

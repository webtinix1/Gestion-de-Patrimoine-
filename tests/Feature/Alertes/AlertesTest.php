<?php

use App\Actions\Alerts\MarkAlertsRead;
use App\Actions\Alerts\RefreshAlerts;
use App\Actions\Loans\RecordLoanReturn;
use App\Enums\AlertType;
use App\Filament\Pages\Alerts;
use App\Models\Alert;
use App\Models\DepreciationSchedule;
use App\Models\User;
use App\Services\Settings;
use App\Support\DefaultRoles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

use function Pest\Livewire\livewire;

require_once __DIR__.'/../Rapports/helpers.php';

beforeEach(function () {
    $this->w = reportingWorld();
    $this->overdue = loanOf($this->w, $this->w->chaise, -3);
    $this->dueSoon = loanOf($this->w, $this->w->ordinateurJeunesse, 2, 'Tente de réception');
    $this->job = openJob($this->w->ordinateurLouange, 20);
    $this->inventory = plannedInventory($this->w, 5);
});

function types(): array
{
    return Alert::query()->active()->get()->map(fn (Alert $alert): string => $alert->type->value.':'.$alert->subject_id)->sort()->values()->all();
}

it('calcule les alertes de RG-90 : retard, échéance proche, intervention, hors service, inventaire, fin d\'amortissement', function () {
    DepreciationSchedule::query()->where('asset_id', $this->w->ordinateurLouange->id)->update(['ends_on' => today()->addDays(40)->toDateString()]);

    $report = app(RefreshAlerts::class)();

    expect($report['raised'])->toBe(6)
        ->and(types())->toBe(collect([
            'loan_overdue:'.$this->overdue->id,
            'loan_due_soon:'.$this->dueSoon->id,
            'maintenance_open:'.$this->job->id,
            'out_of_service:'.$this->w->groupe->id,
            'inventory_upcoming:'.$this->inventory->id,
            'depreciation_ending:'.$this->w->ordinateurLouange->id,
        ])->sort()->values()->all());
});

it('respecte les délais paramétrables (RG-90)', function () {
    app(Settings::class)->set(Settings::ALERT_MAINTENANCE_DAYS, 30);
    app(Settings::class)->set(Settings::ALERT_INVENTORY_DAYS, 2);
    app(Settings::class)->set(Settings::ALERT_LOAN_DUE_DAYS, 1);

    app(RefreshAlerts::class)();

    expect(types())->toBe(collect(['loan_overdue:'.$this->overdue->id, 'out_of_service:'.$this->w->groupe->id])->sort()->values()->all());
});

it('est idempotente : relancer ne crée aucun doublon', function () {
    app(RefreshAlerts::class)();
    $first = Alert::query()->count();

    $second = app(RefreshAlerts::class)();
    Artisan::call('patrimoine:calculer-alertes');

    expect(Alert::query()->count())->toBe($first)
        ->and($second['raised'])->toBe(0)
        ->and($second['kept'])->toBe($first);
});

it('lève l\'alerte quand sa cause disparaît (RG-91) et la recrée si la cause revient', function () {
    app(RefreshAlerts::class)();
    $alert = Alert::query()->where('type', AlertType::LoanOverdue->value)->firstOrFail();
    app(MarkAlertsRead::class)($this->w->manager, [$alert->id]);

    app(RecordLoanReturn::class)($this->overdue->load('items'), [
        'returned_on' => today()->toDateString(),
        'items' => [$this->overdue->items->first()->id => ['condition_back_id' => $this->w->bon->id, 'return_notes' => null]],
    ]);
    app(RefreshAlerts::class)();

    expect($alert->fresh()->resolved_at)->not->toBeNull()
        ->and(Alert::query()->active()->visibleTo($this->w->manager)->where('type', AlertType::LoanOverdue->value)->count())->toBe(0);

    // La cause revient : l'alerte réapparaît, à lire de nouveau.
    $alert->forceFill(['resolved_at' => null])->save();
    Alert::query()->whereKey($alert->id)->update(['resolved_at' => now()]);
    $this->overdue->forceFill([])->saveQuietly();
    \Illuminate\Support\Facades\DB::table('loans')->where('id', $this->overdue->id)->update(['status' => 'ongoing', 'returned_on' => null]);
    app(RefreshAlerts::class)();

    expect($alert->fresh()->resolved_at)->toBeNull()
        ->and(Alert::query()->where('fingerprint', $alert->fingerprint)->count())->toBe(1)
        ->and(Alert::query()->unreadBy($this->w->manager)->whereKey($alert->id)->exists())->toBeTrue();
});

it('est planifiée toutes les heures', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'patrimoine:calculer-alertes'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});

it('marque comme lu pour l\'utilisateur seul (RG-91)', function () {
    app(RefreshAlerts::class)();
    $total = Alert::query()->active()->visibleTo($this->w->manager)->count();

    $marked = app(MarkAlertsRead::class)($this->w->manager);

    expect($marked)->toBe($total)
        ->and(Alert::query()->active()->visibleTo($this->w->manager)->unreadBy($this->w->manager)->count())->toBe(0)
        // Un deuxième appel ne duplique rien.
        ->and(app(MarkAlertsRead::class)($this->w->manager))->toBe(0);

    $this->actingAs($this->w->admin);

    expect(Alert::query()->active()->visibleTo($this->w->admin)->unreadBy($this->w->admin)->count())->toBe($total)
        ->and(fn () => app(MarkAlertsRead::class)($this->w->manager))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('affiche l\'écran E03, filtre par type et marque tout comme lu', function () {
    app(RefreshAlerts::class)();

    livewire(Alerts::class)
        ->assertSee('Retard de retour')
        ->assertSee($this->w->chaise->number)
        ->assertSee('Groupe électrogène Jeunesse')
        ->call('selectGroup', 'out_of_service')
        ->assertSee('Groupe électrogène Jeunesse')
        ->assertDontSee('Retard de retour')
        ->callAction('markAllRead')
        ->assertDontSee('Marquer comme lue');

    expect(Alerts::unreadCount())->toBe(0);
});

it('limite les alertes au périmètre : un responsable ne voit que son département (RG-81)', function () {
    app(RefreshAlerts::class)();

    $this->actingAs($this->w->headLouange);
    $louange = Alert::query()->active()->visibleTo($this->w->headLouange)->get();

    // Louange : le prêt de la chaise, l'intervention sur l'ordinateur ; pas le groupe ni le prêt de Jeunesse.
    // L'inventaire de tout le site n'est pas contenu dans un département : il n'apparaît qu'aux personnes désignées (QL9-6).
    expect($louange->map(fn (Alert $a) => $a->type->value.':'.$a->subject_id)->sort()->values()->all())->toBe(collect([
        'loan_overdue:'.$this->overdue->id,
        'maintenance_open:'.$this->job->id,
    ])->sort()->values()->all());

    $this->actingAs($this->w->headJeunesse);
    $jeunesse = Alert::query()->active()->visibleTo($this->w->headJeunesse)->get();

    expect($jeunesse->map(fn (Alert $a) => $a->type->value.':'.$a->subject_id)->sort()->values()->all())->toBe(collect([
        'loan_due_soon:'.$this->dueSoon->id,
        'out_of_service:'.$this->w->groupe->id,
    ])->sort()->values()->all());

    // L'écran n'expose pas non plus le bien d'un autre département.
    livewire(Alerts::class)
        ->assertSee('Groupe électrogène Jeunesse')
        ->assertDontSee('Chaise Louange')
        ->assertDontSee('Ordinateur Louange');
});

it('refuse de marquer lue une alerte hors périmètre, même avec un identifiant forcé via Livewire', function () {
    app(RefreshAlerts::class)();
    $forbidden = Alert::query()->where('type', AlertType::OutOfService->value)->firstOrFail();

    $this->actingAs($this->w->headLouange);

    livewire(Alerts::class)->call('markRead', $forbidden->id);

    expect($forbidden->readers()->count())->toBe(0)
        ->and(app(MarkAlertsRead::class)($this->w->headLouange, [$forbidden->id]))->toBe(0);
});

it('ne montre pas les alertes d\'un module que le rôle ne peut pas consulter', function () {
    app(RefreshAlerts::class)();
    $user = User::factory()->create();
    $user->givePermissionTo('assets.view');
    $this->actingAs($user);

    $types = Alert::query()->active()->visibleTo($user)->get()->map(fn (Alert $a) => $a->type);

    expect($types->contains(AlertType::LoanOverdue))->toBeFalse()
        ->and($types->contains(AlertType::OutOfService))->toBeTrue();
});

it('masque les écarts d\'inventaire quand il n\'en reste aucun dans le périmètre', function () {
    $inventory = plannedInventory($this->w, 30, 'INV-2026-020');
    $inventory->forceFill(['status' => App\Enums\InventoryStatus::Closed, 'closed_at' => now()])->save();
    \Illuminate\Support\Facades\DB::table('inventory_lines')->insert([
        'inventory_id' => $inventory->id, 'asset_id' => $this->w->groupe->id, 'expected' => true, 'result' => 'missing',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([$this->w->headLouange, $this->w->headJeunesse] as $head) {
        \Illuminate\Support\Facades\DB::table('inventory_participants')->insert(['inventory_id' => $inventory->id, 'user_id' => $head->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    app(RefreshAlerts::class)();

    $gaps = function (User $user): int {
        test()->actingAs($user);

        return Alert::query()->active()->visibleTo($user)->where('type', AlertType::InventoryGaps->value)->count();
    };

    // Le responsable de Louange n'a aucun écart dans son département : pas d'alerte. Celui de Jeunesse, si.
    expect($gaps($this->w->manager))->toBe(1)
        ->and($gaps($this->w->headLouange))->toBe(0)
        ->and($gaps($this->w->headJeunesse))->toBe(1);
});

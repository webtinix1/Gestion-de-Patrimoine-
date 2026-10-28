<?php

use App\Actions\Reporting\ExportReportToPdf;
use App\Actions\Reporting\GenerateReport;
use App\Enums\AlertType;
use App\Filament\Pages\Alerts;
use App\Filament\Pages\Dashboard;
use App\Models\Alert;
use App\Models\User;
use App\Services\Reporting\DashboardData;
use App\Support\Reports\ReportCatalog;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RolesSeeder;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->seed(RolesSeeder::class);
    $this->seed(ReferenceSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->admin = User::query()->where('username', DemoSeeder::ADMIN_USERNAME)->firstOrFail();
    $this->actingAs($this->admin);
});

it('calcule les alertes de la démonstration (retards de prêt, hors service, interventions) sans doublon', function () {
    $types = Alert::query()->active()->pluck('type')->map(fn (AlertType $type): string => $type->value)->unique()->all();

    expect(Alert::query()->active()->where('type', AlertType::LoanOverdue->value)->count())->toBe(2)
        ->and($types)->toContain('out_of_service')
        ->and(Alert::query()->count())->toBe(Alert::query()->distinct('fingerprint')->count('fingerprint'));

    livewire(Alerts::class)->assertSee('Retard de retour');
});

it('produit tous les rapports du catalogue, en aperçu et en PDF, sur les données de démonstration', function () {
    foreach (array_keys(ReportCatalog::all()) as $key) {
        $data = app(GenerateReport::class)($this->admin, $key, [], forExport: true);

        expect($data->headings)->not->toBeEmpty()
            ->and(app(ExportReportToPdf::class)->render($data)->output())->toStartWith('%PDF');
    }
});

it('affiche le tableau de bord de démonstration avec les chiffres des rapports', function () {
    $overview = app(DashboardData::class)->overview($this->admin);
    $general = app(GenerateReport::class)($this->admin, 'general', []);

    expect($overview['counters']['active'])->toBe($general->count())
        ->and($overview['values']['acquisition'])->toBeGreaterThan(0);

    livewire(Dashboard::class)->assertSee('Total des biens');
});

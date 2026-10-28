<?php

use App\Actions\Loans\ExportLoanSheet;
use App\Actions\Reporting\ExportReportToPdf;
use App\Actions\Reporting\GenerateReport;
use App\Exports\ReportExport;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Models\User;
use App\Services\DepreciationPlanReport;
use App\Services\Reporting\ReportBuilder;
use App\Services\Settings;
use App\Support\Reports\ReportCatalog;
use App\Support\Reports\ReportFilters;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\PdfInspector;

use function Pest\Livewire\livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->w = reportingWorld();
    $this->loan = loanOf($this->w, $this->w->chaise, -3);
    openJob($this->w->groupe, 20);
});

it('réserve chaque rapport au droit « exporter » du module concerné (QL11-1)', function () {
    $keys = fn (User $user): array => collect(ReportCatalog::availableTo($user))->flatten()->map(fn ($report) => $report->key)->sort()->values()->all();
    $all = collect(ReportCatalog::all())->keys()->sort()->values()->all();

    expect($keys($this->w->admin))->toBe($all)
        ->and($keys($this->w->manager))->toBe($all)
        ->and($keys($this->w->accountant))->not->toContain('loans')
        ->and($keys($this->w->accountant))->toContain('depreciation_register', 'disposals', 'general')
        // La matrice livrée ne donne aucun droit d'export de module au responsable ni à la consultation.
        ->and($keys($this->w->headLouange))->toBe([])
        ->and($keys($this->w->viewer))->toBe([]);
});

it('refuse l\'accès au catalogue, par l\'URL comme par Livewire, à qui n\'a aucun rapport', function () {
    $this->actingAs($this->w->headLouange);
    $this->get(Reports::getUrl())->assertForbidden();

    $this->actingAs($this->w->accountant);
    $this->get(Reports::getUrl(['rapport' => 'loans']))->assertForbidden();

    livewire(Reports::class)->set('report', 'loans')->assertForbidden();
});

it('génère l\'aperçu avec les filtres communs de l\'exigence 15.3', function () {
    $this->actingAs($this->w->manager);

    livewire(Reports::class)
        ->assertSee('Inventaire général')
        ->assertSee('Chaise Louange')
        ->assertSee('Groupe électrogène Jeunesse')
        ->set('filters.department_id', $this->w->louange->id)
        ->assertSee('Chaise Louange')
        ->assertDontSee('Groupe électrogène Jeunesse')
        ->set('filters.department_id', '')
        ->set('filters.status', 'out_of_service')
        ->assertSee('Groupe électrogène Jeunesse')
        ->assertDontSee('Chaise Louange')
        ->set('filters', ['from' => '2099-01-01'])
        ->assertSee('Aucune ligne pour ces filtres');
});

it('applique les filtres que chaque rapport accepte et ignore les autres', function () {
    $builder = app(ReportBuilder::class);
    $byFilters = fn (string $key, array $filters) => $builder->build(ReportCatalog::find($key), ReportFilters::forReport(ReportCatalog::find($key), $filters), $this->w->manager);

    // Le statut « en service » est un statut de bien : il ne filtre pas les prêts (qui ont leurs propres statuts).
    expect($byFilters('loans', ['status' => 'in_service'])->count())->toBe(1)
        ->and($byFilters('loans', ['status' => 'returned'])->count())->toBe(0)
        ->and($byFilters('loans', ['department_id' => $this->w->jeunesse->id])->count())->toBe(0)
        ->and($byFilters('loans', ['department_id' => $this->w->louange->id])->count())->toBe(1)
        ->and($byFilters('maintenance', ['department_id' => $this->w->jeunesse->id])->count())->toBe(1)
        ->and($byFilters('maintenance', ['category_id' => $this->w->materiel->id])->count())->toBe(0)
        ->and($byFilters('general', ['category_id' => $this->w->materiel->id])->count())->toBe(2)
        ->and($byFilters('general', ['site_id' => $this->w->siege->id, 'holder_id' => 999999])->count())->toBe(4)
        ->and($byFilters('depreciation_register', ['year' => 2025])->count())->toBe(2)
        ->and($byFilters('depreciation_register', ['department_id' => $this->w->louange->id])->count())->toBe(1)
        ->and($byFilters('acquisitions', [])->count())->toBe(0)
        ->and($byFilters('movements', [])->count())->toBeGreaterThanOrEqual(0)
        ->and($byFilters('disposals', [])->count())->toBe(0)
        ->and($byFilters('inventory_gaps', [])->count())->toBe(0);
});

it('ne produit un rapport que pour qui y a droit', function () {
    $generate = app(GenerateReport::class);

    expect(fn () => $generate($this->w->viewer, 'general', []))->toThrow(AuthorizationException::class)
        ->and(fn () => $generate($this->w->accountant, 'loans', []))->toThrow(AuthorizationException::class)
        ->and(fn () => $generate($this->w->accountant, 'loans', [], forExport: true))->toThrow(AuthorizationException::class)
        ->and(fn () => $generate($this->w->manager, 'inconnu', []))->toThrow(InvalidArgumentException::class)
        ->and($generate($this->w->accountant, 'depreciation_register', [])->count())->toBe(2)
        ->and(Gate::forUser($this->w->manager)->allows('export', ReportCatalog::find('general')))->toBeTrue();
});

it('exporte en PDF hors ligne, avec en-tête de l\'Église et sans aucune URL distante', function () {
    app(Settings::class)->set(Settings::CHURCH_NAME, 'Église Évangélique Exemple');
    app(Settings::class)->set(Settings::CHURCH_ACRONYM, 'EEE');

    $data = app(GenerateReport::class)($this->w->manager, 'general', [], forExport: true);
    $html = view('pdf.report', ['data' => $data])->render();

    expect($html)->toContain('Église Évangélique Exemple')
        ->toContain('(EEE)')
        ->toContain('Chaise Louange')
        ->not->toContain('http')
        ->not->toContain('<link')
        ->not->toContain('@import')
        ->not->toContain('url(');

    $pdf = app(ExportReportToPdf::class)->render($data)->output();

    // Pied de page de chaque page : « Page x / y » écrit par le canvas, jamais par une ressource distante.
    expect($pdf)->toStartWith('%PDF')
        ->and(in_array('PAGE 1 / 1', (new PdfInspector($pdf))->texts(), true))->toBeTrue();

    livewire(Reports::class)->callAction('exportPdf')->assertFileDownloaded('rapport-general-'.now()->format('Y-m-d').'.pdf');
});

it('exporte en Excel sans formule injectable : les textes restent du texte', function () {
    $data = app(GenerateReport::class)($this->w->manager, 'general', [], forExport: true);

    $binary = Excel::raw(new ReportExport($data), ExcelWriter::XLSX);
    $file = tempnam(sys_get_temp_dir(), 'rapport').'.xlsx';
    file_put_contents($file, $binary);
    $sheet = IOFactory::load($file)->getActiveSheet();
    @unlink($file);

    $found = false;

    foreach ($sheet->getRowIterator() as $row) {
        $cell = $sheet->getCell('B'.$row->getRowIndex());

        if (str_contains((string) $cell->getValue(), 'Ordinateur Jeunesse')) {
            $found = true;
            expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
                ->and($cell->getValue())->toBe('=1+1 Ordinateur Jeunesse');
        }
    }

    $amount = $sheet->getCell('L2');

    expect($found)->toBeTrue()
        ->and($sheet->getCell('A1')->getValue())->toBe('N° patrimonial')
        ->and($amount->getDataType())->toBe(DataType::TYPE_NUMERIC);

    foreach ($sheet->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            expect($cell->isFormula())->toBeFalse();
        }
    }

    Excel::fake();
    livewire(Reports::class)->callAction('exportExcel');
    Excel::assertDownloaded('rapport-general-'.now()->format('Y-m-d').'.xlsx');
});

it('limite aux biens du périmètre les exports du gestionnaire à périmètre restreint', function () {
    $this->actingAs($this->w->limitedManager);

    $pdfHtml = view('pdf.report', ['data' => app(GenerateReport::class)($this->w->limitedManager, 'general', ['department_id' => $this->w->jeunesse->id], forExport: true)])->render();
    $excel = (new ReportExport(app(GenerateReport::class)($this->w->limitedManager, 'by_department', [], forExport: true)))->array();

    expect($pdfHtml)->toContain('Chaise Louange')->not->toContain('Groupe électrogène Jeunesse')->not->toContain('Ordinateur Jeunesse')
        ->and(collect($excel)->pluck(0)->join(' '))->toContain('Louange')->not->toContain('Jeunesse');

    livewire(Reports::class)
        ->set('filters.department_id', $this->w->jeunesse->id)
        ->assertDontSee('Groupe électrogène Jeunesse')
        ->assertSee('Chaise Louange');
});

it('ne laisse pas un filtre mal formé casser ni élargir le rapport', function () {
    $filters = ReportFilters::fromArray(['site_id' => ['x'], 'department_id' => '1; drop table', 'from' => '31/12/2026', 'to' => '2026-02-31', 'year' => 'abc', 'status' => '<script>']);

    expect($filters->siteId)->toBeNull()
        ->and($filters->departmentId)->toBeNull()
        ->and($filters->from)->toBeNull()
        ->and($filters->to)->toBeNull()
        ->and($filters->year)->toBeNull()
        ->and($filters->status)->toBeNull();
});

it('imprime la fiche de prêt (E45) avec en-tête, conditions et emplacements de signature', function () {
    app(Settings::class)->set(Settings::CHURCH_NAME, 'Église Évangélique Exemple');
    app(Settings::class)->set(Settings::CHURCH_ACRONYM, 'EEE');

    $html = view('pdf.loan-sheet', [
        'loan' => $this->loan->load(['items.asset', 'items.conditionOut', 'items.conditionBack', 'responsible']),
        'churchName' => 'Église Évangélique Exemple', 'churchAcronym' => 'EEE', 'editedAt' => now(), 'editedBy' => 'Albertine Okemba',
    ])->render();

    expect($html)->toContain('Église Évangélique Exemple')
        ->toContain($this->loan->reference)
        ->toContain($this->w->chaise->number)
        ->toContain('Chorale Béthel')
        ->toContain('Conditions')
        ->toContain('Constat au retour')
        ->toContain('Pour l\'Église')
        ->toContain('Le bénéficiaire')
        ->not->toContain('http');

    expect(app(ExportLoanSheet::class)->render($this->loan)->output())->toStartWith('%PDF');

    livewire(ViewLoan::class, ['record' => $this->loan->getRouteKey()])
        ->assertActionVisible('printSheet')
        ->callAction('printSheet')
        ->assertFileDownloaded('fiche-pret-'.$this->loan->reference.'.pdf');
});

it('réserve la fiche de prêt au droit « exporter » des prêts et au périmètre', function () {
    $this->actingAs($this->w->headLouange);
    livewire(ViewLoan::class, ['record' => $this->loan->getRouteKey()])->assertActionHidden('printSheet');
    expect(fn () => app(ExportLoanSheet::class)($this->loan, $this->w->headLouange))->toThrow(AuthorizationException::class);

    // Un prêt d'un autre département n'est même pas atteignable par l'URL.
    $this->actingAs($this->w->manager);
    $other = loanOf($this->w, $this->w->ordinateurJeunesse, 4, 'Autre');
    $this->actingAs($this->w->limitedManager);
    $this->get(LoanResource::getUrl('view', ['record' => $other]))->assertNotFound();
});

it('harmonise le plan d\'amortissement avec le gabarit E45', function () {
    $this->actingAs($this->w->manager);
    $html = view('pdf.depreciation-schedule', [
        'asset' => $this->w->ordinateurLouange,
        'report' => app(DepreciationPlanReport::class)->build($this->w->ordinateurLouange),
        'churchName' => 'Église Évangélique Exemple', 'churchAcronym' => 'EEE', 'editedAt' => now(),
    ])->render();

    expect($html)->toContain('Église Évangélique Exemple')
        ->toContain('Plan d\'amortissement')
        ->toContain($this->w->ordinateurLouange->number)
        ->toContain('Le gestionnaire du patrimoine')
        ->toContain('Le responsable comptable')
        ->not->toContain('http');
});

<?php

use App\Actions\Documents\AttachDocuments;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\DetachDocument;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Documents\Pages\ManageDocuments;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Settings;
use App\Support\DefaultRoles;
use App\Support\FileSize;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
    $this->actingAs($this->manager);
    $this->asset = Asset::factory()->create(['number' => 'PAT-000502']);
    $this->type = DocumentType::factory()->create(['label' => 'Facture']);
});

function relationManager(Asset $asset): Testable
{
    return livewire(DocumentsRelationManager::class, ['ownerRecord' => $asset, 'pageClass' => ViewAsset::class]);
}

function attachedDocument(Asset $asset, array $attributes = []): Document
{
    $document = Document::factory()->create($attributes);
    Storage::disk('local')->put($document->path, 'contenu');
    $document->assets()->attach($asset);

    return $document;
}

it('ajoute des documents à un bien, hors du dossier public, avec le nom d\'origine et le type (RG-66)', function () {
    relationManager($this->asset)
        ->callAction(TestAction::make('attach')->table(), [
            'files' => [
                UploadedFile::fake()->create('facture-mars.pdf', 120, 'application/pdf'),
                UploadedFile::fake()->image('photo.png'),
            ],
            'document_type_id' => $this->type->id,
        ])
        ->assertHasNoFormErrors();

    $documents = $this->asset->documents()->orderBy('original_name')->get();

    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('original_name')->all())->toBe(['facture-mars.pdf', 'photo.png'])
        ->and($documents->first()->document_type_id)->toBe($this->type->id)
        ->and($documents->first()->uploaded_by)->toBe($this->manager->id)
        ->and($documents->first()->mime)->toBe('application/pdf')
        ->and($documents->first()->path)->toStartWith('documents/');

    foreach ($documents as $document) {
        Storage::disk('local')->assertExists($document->path);
    }
});

it('range les fichiers sur le disque privé, hors du dossier public (RG-66)', function () {
    $root = (require config_path('filesystems.php'))['disks']['local']['root'];

    expect($root)->toBe(storage_path('app/private'))
        ->and(str_starts_with($root, public_path()))->toBeFalse();
});

it('exige un type de document', function () {
    relationManager($this->asset)
        ->callAction(TestAction::make('attach')->table(), [
            'files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
            'document_type_id' => null,
        ])
        ->assertHasFormErrors(['document_type_id' => 'required']);

    expect(Document::query()->count())->toBe(0);
});

it('refuse un format interdit', function () {
    relationManager($this->asset)
        ->callAction(TestAction::make('attach')->table(), [
            'files' => [UploadedFile::fake()->create('programme.exe', 10, 'application/x-msdownload')],
            'document_type_id' => $this->type->id,
        ])
        ->assertHasFormErrors(['files']);

    expect(Document::query()->count())->toBe(0);
});

it('refuse un fichier plus gros que la taille maximale des paramètres', function () {
    app(Settings::class)->set(Settings::DOCUMENT_MAX_SIZE_KB, 100);

    relationManager($this->asset)
        ->callAction(TestAction::make('attach')->table(), [
            'files' => [UploadedFile::fake()->create('lourd.pdf', 500, 'application/pdf')],
            'document_type_id' => $this->type->id,
        ])
        ->assertHasFormErrors(['files']);

    expect(Document::query()->count())->toBe(0);
});

it('journalise l\'ajout et le retrait avec le bien comme sujet (RG-70)', function () {
    relationManager($this->asset)
        ->callAction(TestAction::make('attach')->table(), [
            'files' => [UploadedFile::fake()->create('garantie.pdf', 10, 'application/pdf')],
            'document_type_id' => $this->type->id,
        ]);

    $document = $this->asset->documents()->firstOrFail();

    $added = Activity::query()->where('log_name', 'documents')->where('event', 'document_attached')->firstOrFail();
    expect($added->subject_id)->toBe($this->asset->id)
        ->and($added->subject_type)->toBe($this->asset->getMorphClass())
        ->and($added->causer_id)->toBe($this->manager->id)
        ->and($added->properties['document'])->toBe('garantie.pdf');

    relationManager($this->asset)->callAction(TestAction::make('detach')->table($document));

    $removed = Activity::query()->where('log_name', 'documents')->where('event', 'document_detached')->firstOrFail();
    expect($removed->subject_id)->toBe($this->asset->id)
        ->and($removed->properties['document'])->toBe('garantie.pdf');
});

it('détacher un document conserve le fichier', function () {
    $document = attachedDocument($this->asset);

    relationManager($this->asset)->callAction(TestAction::make('detach')->table($document));

    expect($this->asset->documents()->count())->toBe(0)
        ->and(Document::query()->whereKey($document->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($document->path);
});

it('liste les documents du bien avec nom, type et taille lisible', function () {
    $document = attachedDocument($this->asset, ['original_name' => 'devis.pdf', 'size' => 1536 * 1024]);

    relationManager($this->asset)
        ->assertCanSeeTableRecords([$document])
        ->assertTableActionVisible('open', $document)
        ->assertTableActionVisible('download', $document)
        ->assertSee('devis.pdf')
        ->assertSee('1,5 Mo');

    expect(FileSize::format(512))->toBe('512 o')
        ->and(FileSize::format(300 * 1024))->toBe('300 Ko');
});

it('est en lecture seule sur un bien sorti du patrimoine', function () {
    $disposed = Asset::factory()->disposed()->create();
    $document = attachedDocument($disposed);

    relationManager($disposed)
        ->assertCanSeeTableRecords([$document])
        ->assertActionHidden(TestAction::make('attach')->table())
        ->assertActionHidden(TestAction::make('detach')->table($document));
});

it('n\'ajoute et ne retire rien sur un bien sorti, même hors interface', function () {
    $disposed = Asset::factory()->disposed()->create();
    $document = attachedDocument($disposed);

    expect(fn () => app(AttachDocuments::class)(
        [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], $this->type, [$disposed],
    ))->toThrow(DomainException::class)
        ->and(fn () => app(DetachDocument::class)($document, $disposed))->toThrow(DomainException::class);
});

it('ne laisse pas supprimer un document encore rattaché, mais supprime un document orphelin', function () {
    $document = attachedDocument($this->asset);

    expect(fn () => app(DeleteDocument::class)($document))->toThrow(DomainException::class);

    $document->assets()->detach();
    app(DeleteDocument::class)($document);

    expect(Document::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($document->path);
});

describe('accès aux fichiers (RG-66)', function () {
    it('sert le PDF en ligne avec le nom d\'origine, et force le téléchargement sur demande', function () {
        $document = attachedDocument($this->asset, ['original_name' => 'facture.pdf']);

        $inline = $this->get($document->fileUrl())->assertOk();
        expect($inline->headers->get('Content-Disposition'))->toContain('inline')->toContain('facture.pdf');

        $download = $this->get($document->fileUrl(download: true))->assertOk();
        expect($download->headers->get('Content-Disposition'))->toContain('attachment')->toContain('facture.pdf');
    });

    it('télécharge un fichier Word au lieu de l\'afficher', function () {
        $document = attachedDocument($this->asset, [
            'original_name' => 'contrat.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        expect($this->get($document->fileUrl())->assertOk()->headers->get('Content-Disposition'))->toContain('attachment');
    });

    it('renvoie un invité vers la connexion', function () {
        $document = attachedDocument($this->asset);
        auth()->logout();

        $this->get($document->fileUrl())->assertRedirect();
    });

    it('refuse un utilisateur sans le droit documents.view', function () {
        $document = attachedDocument($this->asset);

        $this->actingAs(User::factory()->create());

        $this->get($document->fileUrl())->assertForbidden();
    });

    it('refuse un document dont le seul bien est hors du périmètre', function () {
        $louange = Department::factory()->create();
        $jeunesse = Department::factory()->create();
        $own = Asset::factory()->create(['department_id' => $louange->id]);
        $other = Asset::factory()->create(['department_id' => $jeunesse->id]);
        $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
        $head->departments()->attach($louange);

        $hidden = attachedDocument($other);
        $shared = attachedDocument($own);
        $shared->assets()->attach($other);

        $this->actingAs($head);

        $this->get($hidden->fileUrl())->assertNotFound();
        $this->get($shared->fileUrl())->assertOk();
    });

    it('renvoie 404 quand le fichier a disparu du disque', function () {
        $document = attachedDocument($this->asset);
        Storage::disk('local')->delete($document->path);

        $this->get($document->fileUrl())->assertNotFound();
    });
});

describe('liste E28', function () {
    it('affiche les documents avec les numéros de biens et se filtre par type et par période', function () {
        $invoice = attachedDocument($this->asset, ['original_name' => 'facture.pdf', 'document_type_id' => $this->type->id, 'created_at' => '2026-03-10']);
        $guarantee = attachedDocument($this->asset, ['original_name' => 'garantie.pdf', 'created_at' => '2026-09-10']);

        livewire(ManageDocuments::class)
            ->assertCanSeeTableRecords([$invoice, $guarantee])
            ->assertSee('PAT-000502')
            ->filterTable('document_type_id', $this->type->id)
            ->assertCanSeeTableRecords([$invoice])
            ->assertCanNotSeeTableRecords([$guarantee])
            ->resetTableFilters()
            ->filterTable('period', ['from' => '2026-08-01', 'until' => '2026-09-30'])
            ->assertCanSeeTableRecords([$guarantee])
            ->assertCanNotSeeTableRecords([$invoice]);
    });

    it('recherche par nom de fichier ou par numéro de bien rattaché', function () {
        $other = Asset::factory()->create(['number' => 'PAT-000999']);
        $first = attachedDocument($this->asset, ['original_name' => 'facture.pdf']);
        $second = attachedDocument($other, ['original_name' => 'garantie.pdf']);

        livewire(ManageDocuments::class)
            ->searchTable('garantie')
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first])
            ->searchTable('PAT-000502')
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second]);
    });

    it('masque les documents dont tous les biens sont hors périmètre (RG-81)', function () {
        $louange = Department::factory()->create();
        $jeunesse = Department::factory()->create();
        $own = Asset::factory()->create(['department_id' => $louange->id]);
        $other = Asset::factory()->create(['department_id' => $jeunesse->id]);
        $visible = attachedDocument($own);
        $mixed = attachedDocument($own);
        $mixed->assets()->attach($other);
        $hidden = attachedDocument($other);
        $orphan = Document::factory()->create();

        $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
        $head->departments()->attach($louange);
        $this->actingAs($head);

        livewire(ManageDocuments::class)
            ->assertCanSeeTableRecords([$visible, $mixed])
            ->assertCanNotSeeTableRecords([$hidden, $orphan])
            ->assertSee($own->number)
            ->assertDontSee($other->number);
    });

    it('ajoute des documents rattachés à plusieurs biens depuis l\'écran', function () {
        $other = Asset::factory()->create();

        livewire(ManageDocuments::class)
            ->callAction('upload', [
                'files' => [UploadedFile::fake()->create('pv.pdf', 10, 'application/pdf')],
                'document_type_id' => $this->type->id,
                'asset_ids' => [$this->asset->id, $other->id],
            ])
            ->assertHasNoFormErrors();

        $document = Document::query()->firstOrFail();
        expect($document->assets()->pluck('assets.id')->sort()->values()->all())->toBe(collect([$this->asset->id, $other->id])->sort()->values()->all());
        expect(Activity::query()->where('event', 'document_attached')->count())->toBe(2);
    });

    it('exige au moins un bien à l\'ajout', function () {
        livewire(ManageDocuments::class)
            ->callAction('upload', [
                'files' => [UploadedFile::fake()->create('pv.pdf', 10, 'application/pdf')],
                'document_type_id' => $this->type->id,
                'asset_ids' => [],
            ])
            ->assertHasFormErrors(['asset_ids' => 'required']);
    });

    it('n\'est pas accessible sans le droit de consulter les documents', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/documents')->assertForbidden();
    });
});

<?php

use DatabaseSeedersDemoSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Lot 12 : guides utilisateur en PDF, générés hors ligne à partir de docs/guides.
 */
beforeEach(function () {
    $this->sortie = sys_get_temp_dir().DIRECTORY_SEPARATOR.'guides-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    foreach (glob($this->sortie.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        unlink($file);
    }

    if (is_dir($this->sortie)) {
        rmdir($this->sortie);
    }
});

/**
 * @return list<string>
 */
function guideSources(): array
{
    $files = glob(base_path('docs/guides/*.md')) ?: [];
    sort($files);

    return $files;
}

it('livre un guide par profil et un guide d\'administration', function () {
    $names = array_map(fn (string $file): string => basename($file, '.md'), guideSources());

    expect($names)->toContain('01-administrateur', '02-gestionnaire-du-patrimoine', '03-comptable', '04-responsable-de-departement', '05-consultation', '06-administration');
});

it('génère un PDF par guide avec son titre', function () {
    $this->artisan('patrimoine:guides', ['--sortie' => $this->sortie])->assertSuccessful();

    foreach (guideSources() as $source) {
        $pdf = $this->sortie.DIRECTORY_SEPARATOR.basename($source, '.md').'.pdf';

        expect(file_exists($pdf))->toBeTrue("PDF manquant pour {$source}");

        $content = (string) file_get_contents($pdf);
        preg_match('/^#\s+(.+)$/m', (string) file_get_contents($source), $title);
        $texts = implode(' ', (new Tests\Support\PdfInspector($content))->texts());

        expect(str_starts_with($content, '%PDF'))->toBeTrue()
            ->and(mb_strtoupper($texts))->toContain(mb_strtoupper(explode(' ', trim($title[1]))[0]));
    }
});

it('ne référence aucune ressource distante dans les PDF', function () {
    Artisan::call('patrimoine:guides', ['--sortie' => $this->sortie]);

    foreach (glob($this->sortie.DIRECTORY_SEPARATOR.'*.pdf') ?: [] as $pdf) {
        expect((string) file_get_contents($pdf))->not->toMatch('#https?://(?!www\.w3\.org|ns\.adobe\.com|purl\.org|www\.aiim\.org|www\.npes\.org|www\.color\.org)#i');
    }
});

it('n\'écrit aucun mot de passe de démonstration dans les guides', function () {
    foreach (guideSources() as $source) {
        expect((string) file_get_contents($source))->not->toContain(DemoSeeder::ADMIN_PASSWORD);
    }
});

<?php

namespace App\Console\Commands;

use App\Services\Settings;
use App\Support\Pdf\PdfFactory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lot 12 : convertit les guides Markdown de docs/guides en PDF, hors ligne (gabarit commun E45,
 * aucune ressource distante). Le nom et le sigle de l'Église viennent des paramètres.
 */
#[Signature('patrimoine:guides {--sortie=storage/app/guides : Dossier où écrire les PDF}')]
#[Description('Génère les guides utilisateur par profil en PDF à partir de docs/guides')]
class GuidesCommand extends Command
{
    public function handle(Settings $settings): int
    {
        $files = glob(base_path('docs/guides/*.md')) ?: [];
        sort($files);

        if ($files === []) {
            $this->error('Aucun guide trouvé dans docs/guides.');

            return self::FAILURE;
        }

        $option = (string) $this->option('sortie');
        $target = str_replace('\\', '/', preg_match('#^([A-Za-z]:[\\\\/]|/)#', $option) === 1 ? $option : base_path($option));

        if (! is_dir($target) && ! @mkdir($target, 0775, true) && ! is_dir($target)) {
            $this->error("Impossible de créer le dossier {$target}.");

            return self::FAILURE;
        }

        $identity = $this->identity($settings);

        foreach ($files as $file) {
            $markdown = (string) file_get_contents($file);
            $title = preg_match('/^#\s+(.+)$/m', $markdown, $found) === 1 ? trim($found[1]) : basename($file, '.md');
            $body = Str::markdown(
                preg_replace('/^#\s+.+\R/', '', $markdown, 1) ?? $markdown,
                ['html_input' => 'strip', 'allow_unsafe_links' => false],
            );

            $pdf = PdfFactory::view('pdf.guide', [
                'title' => $title,
                'body' => $body,
                'churchName' => $identity['name'],
                'churchAcronym' => $identity['acronym'],
                'editedAt' => now(),
            ]);

            $path = $target.'/'.basename($file, '.md').'.pdf';
            file_put_contents($path, $pdf->output());
            $this->line('PDF écrit : '.$path);
        }

        $this->info(count($files).' guide(s) généré(s).');

        return self::SUCCESS;
    }

    /**
     * Nom et sigle de l'Église ; valeurs par défaut si la base n'est pas joignable (génération hors serveur).
     *
     * @return array{name: string, acronym: string}
     */
    private function identity(Settings $settings): array
    {
        try {
            return ['name' => $settings->string(Settings::CHURCH_NAME), 'acronym' => $settings->string(Settings::CHURCH_ACRONYM)];
        } catch (\Throwable) {
            return ['name' => (string) Settings::DEFAULTS[Settings::CHURCH_NAME], 'acronym' => (string) Settings::DEFAULTS[Settings::CHURCH_ACRONYM]];
        }
    }
}

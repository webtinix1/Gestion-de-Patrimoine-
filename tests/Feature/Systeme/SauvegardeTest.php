<?php

use App\Services\System\Backup\BackupException;
use App\Services\System\Backup\BackupService;
use App\Services\System\Backup\DatabaseTools;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Q20 : sauvegarde et restauration manuelles, sur une base SQLite fichier réelle et des dossiers temporaires.
 */
beforeEach(function () {
    $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pat-sauvegarde-'.bin2hex(random_bytes(4));
    $this->privateDir = $this->work.DIRECTORY_SEPARATOR.'private';
    $this->destination = $this->work.DIRECTORY_SEPARATOR.'disque-externe';
    $this->dbFile = $this->work.DIRECTORY_SEPARATOR.'patrimoine.sqlite';

    mkdir($this->privateDir.DIRECTORY_SEPARATOR.'documents', 0775, true);
    mkdir($this->destination, 0775, true);
    ecrireSqlite($this->dbFile, 'v1');
    file_put_contents($this->privateDir.'/documents/facture.pdf', 'PDF-facture-v1');
    file_put_contents($this->privateDir.'/photo.jpg', 'JPG-photo-v1');

    $this->previousDefault = config('database.default');
    config([
        'database.default' => 'sauvegarde_test',
        'database.connections.sauvegarde_test' => ['driver' => 'sqlite', 'database' => $this->dbFile, 'prefix' => '', 'foreign_key_constraints' => true],
        'filesystems.disks.local.root' => $this->privateDir,
    ]);
});

afterEach(function () {
    Artisan::call('up');
    config(['database.default' => $this->previousDefault]);
    File::deleteDirectory($this->work);
});

function ecrireSqlite(string $path, string $value): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('create table if not exists t (v text)');
    $pdo->exec('delete from t');
    $pdo->exec("insert into t values ('{$value}')");
    $pdo = null;
}

function lireSqlite(string $path): string
{
    $pdo = new PDO('sqlite:'.$path);
    $value = (string) $pdo->query('select v from t')->fetchColumn();
    $pdo = null;

    return $value;
}

function dossiersSauvegarde(string $destination, string $prefix = 'patrimoine-'): array
{
    return array_values(array_filter(
        glob($destination.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [],
        fn (string $dir): bool => str_starts_with(basename($dir), $prefix),
    ));
}

function sauvegarder(object $test): string
{
    $test->artisan('patrimoine:sauvegarder', ['destination' => $test->destination])->assertSuccessful();

    return dossiersSauvegarde($test->destination)[0];
}

/**
 * Faux mysqldump et mysql (scripts PHP) pour tester les échecs d'export et d'import sans serveur.
 */
function fauxOutils(string $dir): string
{
    mkdir($dir, 0775, true);
    $php = PHP_BINARY;

    file_put_contents($dir.'/fake-dump.php', <<<'PHP'
<?php
$mode = trim((string) @file_get_contents(__DIR__.'/dump-mode'));
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--result-file=')) {
        file_put_contents(substr($arg, 14), $mode === 'partial' ? "-- debut de l'export\n" : "-- export\n-- Dump completed on 2026\n");
    }
}
if ($mode === 'partial') {
    fwrite(STDERR, "export interrompu\n");
    exit(1);
}
PHP);
    file_put_contents($dir.'/fake-mysql.php', <<<'PHP'
<?php
$input = stream_get_contents(STDIN);
file_put_contents(__DIR__.'/mysql.log', md5($input)."\n", FILE_APPEND);
$failures = (int) @file_get_contents(__DIR__.'/mysql-failures');
if ($failures > 0) {
    file_put_contents(__DIR__.'/mysql-failures', (string) ($failures - 1));
    fwrite(STDERR, "import interrompu\n");
    exit(1);
}
PHP);
    file_put_contents($dir.'/dump-mode', 'ok');

    foreach (['mysqldump' => 'fake-dump.php', 'mysql' => 'fake-mysql.php'] as $name => $script) {
        if (PHP_OS_FAMILY === 'Windows') {
            file_put_contents($dir."/{$name}.bat", "@\"{$php}\" \"%~dp0{$script}\" %*\r\n");
        } else {
            file_put_contents($dir."/{$name}", "#!/bin/sh\nexec \"{$php}\" \"\$(dirname \"\$0\")/{$script}\" \"\$@\"\n");
            chmod($dir."/{$name}", 0755);
        }
    }

    return $dir;
}

function configurerMysqlFactice(): void
{
    config([
        'database.default' => 'mysql_test',
        'database.connections.mysql_test' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'base_test',
            'username' => 'personne', 'password' => 'x', 'prefix' => '',
        ],
    ]);
}

it('sauvegarde la base et les fichiers privés dans un dossier daté avec un manifeste contrôlé', function () {
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination])
        ->expectsOutputToContain('Sauvegarde terminée et contrôlée')
        ->assertSuccessful();

    $dossiers = dossiersSauvegarde($this->destination);
    expect($dossiers)->toHaveCount(1)
        ->and(basename($dossiers[0]))->toMatch('/^patrimoine-\d{8}-\d{6}$/');

    $dir = $dossiers[0];
    $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);

    expect(lireSqlite($dir.'/base/database.sqlite'))->toBe('v1')
        ->and(file_get_contents($dir.'/fichiers/documents/facture.pdf'))->toBe('PDF-facture-v1')
        ->and($manifest['database']['sha256'])->toBe(hash_file('sha256', $dir.'/base/database.sqlite'))
        ->and(collect($manifest['files'])->pluck('path')->sort()->values()->all())->toBe(['fichiers/documents/facture.pdf', 'fichiers/photo.jpg'])
        ->and(app(BackupService::class)->verify($dir))->toBe([]);
});

it('sauvegarde une base SQLite en mode WAL avec ses dernières écritures', function () {
    $pdo = new PDO('sqlite:'.$this->dbFile);
    $pdo->exec('pragma journal_mode=wal');
    $pdo->exec("update t set v = 'dans-le-journal'");

    $dir = sauvegarder($this);

    expect(lireSqlite($dir.'/base/database.sqlite'))->toBe('dans-le-journal');
    $pdo = null;
});

it('accepte un nom de fichier contenant deux points consécutifs', function () {
    file_put_contents($this->privateDir.'/documents/devis..final.pdf', 'devis');

    $dir = sauvegarder($this);

    expect(file_get_contents($dir.'/fichiers/documents/devis..final.pdf'))->toBe('devis')
        ->and(app(BackupService::class)->verify($dir))->toBe([]);
});

it('refuse une destination absente, qui est un fichier, ou placée dans l\'application', function (string $cas) {
    $destination = match ($cas) {
        'absente' => $this->work.'/nexiste-pas',
        'fichier' => $this->dbFile,
        'public' => public_path(),
        'application' => base_path('tests'),
        'prive' => $this->privateDir,
        'sous-prive' => $this->privateDir.'/documents',
    };

    $this->artisan('patrimoine:sauvegarder', ['destination' => $destination])->assertFailed();

    expect(glob($this->privateDir.'/documents/patrimoine-*'))->toBe([])
        ->and(dossiersSauvegarde(base_path('tests')))->toBe([]);
})->with(['absente', 'fichier', 'public', 'application', 'prive', 'sous-prive']);

it('retire le dossier partiel quand l\'export écrit puis échoue', function () {
    configurerMysqlFactice();
    $outils = fauxOutils($this->work.'/outils');
    file_put_contents($outils.'/dump-mode', 'partial');

    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination, '--bin' => $outils])
        ->expectsOutputToContain('L\'export de la base a échoué')
        ->assertFailed();

    expect(dossiersSauvegarde($this->destination))->toBe([]);
});

it('réserve les commandes à la ligne de commande avec un message clair', function (string $commande, array $arguments) {
    (function () {
        $this->isRunningInConsole = false;
    })->call($this->app);

    $this->artisan($commande, $arguments)
        ->expectsOutputToContain('réservée à la ligne de commande')
        ->assertFailed();

    expect(dossiersSauvegarde($this->destination))->toBe([]);
})->with([
    'sauvegarder' => ['patrimoine:sauvegarder', ['destination' => 'x']],
    'restaurer' => ['patrimoine:restaurer', ['source' => 'x']],
    'installer' => ['patrimoine:installer', []],
]);

it('détecte un fichier modifié ou manquant, un fichier en trop et un manifeste absent', function () {
    $dir = sauvegarder($this);
    $service = app(BackupService::class);

    file_put_contents($dir.'/fichiers/photo.jpg', 'JPG-photo-v2');
    expect($service->verify($dir))->toHaveCount(1)->and($service->verify($dir)[0])->toContain('photo.jpg');

    unlink($dir.'/fichiers/documents/facture.pdf');
    expect($service->verify($dir))->toHaveCount(2);

    file_put_contents($dir.'/fichiers/intrus.txt', 'pas au manifeste');
    expect(implode(' ', $service->verify($dir)))->toContain('fichier en trop')->toContain('intrus.txt');

    unlink($dir.'/manifest.json');
    expect($service->verify($dir)[0])->toContain('manifest.json est absent');
});

it('refuse sans exception brute un manifeste mal formé ou aux chemins dangereux', function (array $manifestChange) {
    $dir = sauvegarder($this);
    $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);
    file_put_contents($dir.'/manifest.json', json_encode(array_replace_recursive($manifest, $manifestChange)));

    expect(app(BackupService::class)->verify($dir))->not->toBe([]);
})->with([
    'entrée mal formée' => [['files' => ['oups']]],
    'fichier de base imposé' => [['database' => ['file' => 'base/autre.sql']]],
    'pilote inconnu' => [['database' => ['driver' => 'pgsql']]],
    'segment ..' => [['files' => [['path' => 'fichiers/../manifest.json', 'size' => 1, 'sha256' => 'x']]]],
    'chemin absolu' => [['files' => [['path' => 'fichiers//etc/passwd', 'size' => 1, 'sha256' => 'x']]]],
    'lecteur' => [['files' => [['path' => 'C:/Windows/win.ini', 'size' => 1, 'sha256' => 'x']]]],
]);

it('trouve les outils MySQL dans le dossier indiqué', function () {
    $bin = $this->work.'/outils';
    mkdir($bin);
    file_put_contents($bin.'/mysqldump.exe', '');

    expect(app(DatabaseTools::class)->findBinary('dump', $bin))->toBe($bin.DIRECTORY_SEPARATOR.'mysqldump.exe');
});

it('restaure la base et les fichiers en maintenance, en gardant l\'état précédent', function () {
    $dir = sauvegarder($this);

    ecrireSqlite($this->dbFile, 'v2-corrompu');
    unlink($this->privateDir.'/photo.jpg');
    file_put_contents($this->privateDir.'/documents/intrus.txt', 'ajout postérieur');
    file_put_contents($this->dbFile.'-wal', 'journal-orphelin');
    file_put_contents($this->dbFile.'.restauration', 'reste-d-un-essai');

    $this->travel(2)->seconds();
    Artisan::call('down');

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain('Restauration terminée')
        ->assertSuccessful();

    expect(lireSqlite($this->dbFile))->toBe('v1')
        ->and(file_exists($this->dbFile.'-wal'))->toBeFalse()
        ->and(file_exists($this->dbFile.'.restauration'))->toBeFalse()
        ->and(file_get_contents($this->privateDir.'/photo.jpg'))->toBe('JPG-photo-v1')
        ->and(file_exists($this->privateDir.'/documents/intrus.txt'))->toBeFalse()
        ->and(glob($this->work.'/private.transit-*'))->toBe([]);

    $securite = dossiersSauvegarde($this->destination, 'avant-restauration-');
    expect($securite)->toHaveCount(1)
        ->and(lireSqlite($securite[0].'/base/database.sqlite'))->toBe('v2-corrompu');

    $anciens = glob($this->work.'/private.avant-restauration-*', GLOB_ONLYDIR);
    expect($anciens)->toHaveCount(1)
        ->and(file_get_contents($anciens[0].'/documents/intrus.txt'))->toBe('ajout postérieur');
});

it('refuse de restaurer quand l\'application n\'est pas en maintenance', function () {
    $dir = sauvegarder($this);
    ecrireSqlite($this->dbFile, 'etat-actuel');

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain('doit être en maintenance')
        ->assertFailed();

    expect(lireSqlite($this->dbFile))->toBe('etat-actuel');
});

it('demande de taper le nom de la base et refuse une confirmation incorrecte', function () {
    $dir = sauvegarder($this);
    ecrireSqlite($this->dbFile, 'etat-actuel');
    Artisan::call('down');

    $this->artisan('patrimoine:restaurer', ['source' => $dir])
        ->expectsQuestion('Pour confirmer, tapez le nom de la base à écraser (patrimoine.sqlite)', 'oui')
        ->expectsOutputToContain('Confirmation incorrecte')
        ->assertFailed();

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'autre'])->assertFailed();
    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--no-interaction' => true])
        ->expectsOutputToContain('Confirmation absente')
        ->assertFailed();

    expect(lireSqlite($this->dbFile))->toBe('etat-actuel');
});

it('refuse de restaurer en production sans --force', function () {
    $dir = sauvegarder($this);
    ecrireSqlite($this->dbFile, 'etat-actuel');
    Artisan::call('down');

    $this->app['env'] = 'production';

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain('refusée en production')
        ->assertFailed();

    expect(lireSqlite($this->dbFile))->toBe('etat-actuel');

    $this->travel(2)->seconds();
    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite', '--force' => true])->assertSuccessful();
    expect(lireSqlite($this->dbFile))->toBe('v1');
});

it('refuse une sauvegarde altérée, avec fichier en trop ou d\'un autre type de base sans rien modifier', function () {
    $dir = sauvegarder($this);
    ecrireSqlite($this->dbFile, 'etat-actuel');
    Artisan::call('down');
    $restaurer = fn (string $message) => $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain($message)->assertFailed();

    file_put_contents($dir.'/fichiers/photo.jpg', 'altérée');
    $restaurer('corrompue ou incomplète');
    file_put_contents($dir.'/fichiers/photo.jpg', 'JPG-photo-v1');

    file_put_contents($dir.'/fichiers/intrus.txt', 'absent du manifeste');
    $restaurer('fichier en trop');
    unlink($dir.'/fichiers/intrus.txt');

    $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);
    $manifest['database']['driver'] = 'mysql';
    file_put_contents($dir.'/manifest.json', json_encode($manifest));
    $restaurer('inattendu');

    $this->artisan('patrimoine:restaurer', ['source' => $this->work.'/inconnu', '--base' => 'x'])->assertFailed();

    expect(lireSqlite($this->dbFile))->toBe('etat-actuel')
        ->and(dossiersSauvegarde($this->destination, 'avant-restauration-'))->toBe([])
        ->and(glob($this->work.'/private.*'))->toBe([]);
});

it('refuse une sauvegarde dont le dossier fichiers est absent sans rien toucher', function () {
    $dir = sauvegarder($this);
    ecrireSqlite($this->dbFile, 'etat-actuel');
    File::deleteDirectory($dir.'/fichiers');
    Artisan::call('down');

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain('fichier manquant')
        ->assertFailed();

    expect(lireSqlite($this->dbFile))->toBe('etat-actuel')
        ->and(file_get_contents($this->privateDir.'/photo.jpg'))->toBe('JPG-photo-v1')
        ->and(dossiersSauvegarde($this->destination, 'avant-restauration-'))->toBe([]);
});

it('rejoue la sauvegarde de sécurité quand l\'import de la base échoue en cours de route', function () {
    configurerMysqlFactice();
    $outils = fauxOutils($this->work.'/outils');
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination, '--bin' => $outils])->assertSuccessful();
    $dir = dossiersSauvegarde($this->destination)[0];

    file_put_contents($this->privateDir.'/documents/recent.txt', 'plus récent que la sauvegarde');
    file_put_contents($outils.'/mysql-failures', '1');
    $this->travel(2)->seconds();
    Artisan::call('down');

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'base_test', '--bin' => $outils])
        ->expectsOutputToContain('remis en place automatiquement')
        ->assertFailed();

    expect(file($outils.'/mysql.log'))->toHaveCount(2)
        ->and(file_get_contents($this->privateDir.'/documents/recent.txt'))->toBe('plus récent que la sauvegarde')
        ->and(file_exists($this->privateDir.'/photo.jpg'))->toBeTrue();
});

it('indique la commande de reprise quand le rejeu de la sauvegarde de sécurité échoue aussi', function () {
    configurerMysqlFactice();
    $outils = fauxOutils($this->work.'/outils');
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination, '--bin' => $outils])->assertSuccessful();
    $dir = dossiersSauvegarde($this->destination)[0];

    file_put_contents($outils.'/mysql-failures', '5');
    $this->travel(2)->seconds();
    Artisan::call('down');

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'base_test', '--bin' => $outils])
        ->expectsOutputToContain('Base partiellement restaurée')
        ->assertFailed();

    expect(dossiersSauvegarde($this->destination, 'avant-restauration-'))->toHaveCount(1)
        ->and(file_exists($this->privateDir.'/photo.jpg'))->toBeTrue();
});

it('lève une erreur lisible quand la base est en mémoire', function () {
    config(['database.connections.sauvegarde_test.database' => ':memory:']);

    expect(fn () => app(BackupService::class)->create($this->destination))
        ->toThrow(BackupException::class, 'en mémoire');
});

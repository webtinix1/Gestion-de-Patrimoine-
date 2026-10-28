<?php

use App\Services\System\Backup\BackupException;
use App\Services\System\Backup\BackupService;
use App\Services\System\Backup\DatabaseTools;
use Illuminate\Support\Facades\File;

/**
 * Q20 : sauvegarde et restauration manuelles, sur une base SQLite fichier et des dossiers temporaires.
 */
beforeEach(function () {
    $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pat-sauvegarde-'.bin2hex(random_bytes(4));
    $this->privateDir = $this->work.DIRECTORY_SEPARATOR.'private';
    $this->destination = $this->work.DIRECTORY_SEPARATOR.'disque-externe';
    $this->dbFile = $this->work.DIRECTORY_SEPARATOR.'patrimoine.sqlite';

    mkdir($this->privateDir.DIRECTORY_SEPARATOR.'documents', 0775, true);
    mkdir($this->destination, 0775, true);
    file_put_contents($this->dbFile, "SQLite format 3\0contenu-base-v1");
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
    config(['database.default' => $this->previousDefault]);
    File::deleteDirectory($this->work);
});

function dossiersSauvegarde(string $destination, string $prefix = 'patrimoine-'): array
{
    return array_values(array_filter(
        glob($destination.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [],
        fn (string $dir): bool => str_starts_with(basename($dir), $prefix),
    ));
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

    expect(file_get_contents($dir.'/base/database.sqlite'))->toBe("SQLite format 3\0contenu-base-v1")
        ->and(file_get_contents($dir.'/fichiers/documents/facture.pdf'))->toBe('PDF-facture-v1')
        ->and(file_get_contents($dir.'/fichiers/photo.jpg'))->toBe('JPG-photo-v1')
        ->and($manifest['database']['sha256'])->toBe(hash_file('sha256', $this->dbFile))
        ->and($manifest['database']['size'])->toBe(filesize($this->dbFile))
        ->and(collect($manifest['files'])->pluck('path')->sort()->values()->all())->toBe(['fichiers/documents/facture.pdf', 'fichiers/photo.jpg'])
        ->and(app(BackupService::class)->verify($dir))->toBe([]);
});

it('refuse une destination absente, qui est un fichier, ou placée dans le public ou les fichiers privés', function (string $cas) {
    $destination = match ($cas) {
        'absente' => $this->work.'/nexiste-pas',
        'fichier' => $this->dbFile,
        'public' => public_path(),
        'prive' => $this->privateDir,
        'sous-prive' => $this->privateDir.'/documents',
    };

    $this->artisan('patrimoine:sauvegarder', ['destination' => $destination])->assertFailed();

    expect(dossiersSauvegarde($this->privateDir))->toBe([])
        ->and(glob($this->privateDir.'/documents/patrimoine-*'))->toBe([]);
})->with(['absente', 'fichier', 'public', 'prive', 'sous-prive']);

it('retire le dossier partiel quand l\'export échoue', function () {
    config([
        'database.default' => 'mysql_test',
        'database.connections.mysql_test' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'base_inexistante_test',
            'username' => 'personne', 'password' => 'x', 'prefix' => '',
        ],
    ]);

    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination, '--bin' => $this->work.'/rien'])->assertFailed();

    expect(dossiersSauvegarde($this->destination))->toBe([]);
});

it('détecte un fichier modifié, manquant ou un manifeste absent', function () {
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination])->assertSuccessful();
    $dir = dossiersSauvegarde($this->destination)[0];
    $service = app(BackupService::class);

    file_put_contents($dir.'/fichiers/photo.jpg', 'JPG-photo-v2');
    expect($service->verify($dir))->toHaveCount(1)->and($service->verify($dir)[0])->toContain('photo.jpg');

    unlink($dir.'/fichiers/documents/facture.pdf');
    expect($service->verify($dir))->toHaveCount(2);

    unlink($dir.'/manifest.json');
    expect($service->verify($dir)[0])->toContain('manifest.json est absent');
});

it('trouve les outils MySQL dans le dossier indiqué', function () {
    $bin = $this->work.'/outils';
    mkdir($bin);
    file_put_contents($bin.'/mysqldump.exe', '');

    expect(app(DatabaseTools::class)->findBinary('dump', $bin))->toBe($bin.DIRECTORY_SEPARATOR.'mysqldump.exe');
});

it('restaure la base et les fichiers après confirmation, en gardant l\'état précédent', function () {
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination])->assertSuccessful();
    $dir = dossiersSauvegarde($this->destination)[0];

    file_put_contents($this->dbFile, "SQLite format 3\0contenu-base-v2-corrompu");
    unlink($this->privateDir.'/photo.jpg');
    file_put_contents($this->privateDir.'/documents/intrus.txt', 'ajout postérieur');

    $this->travel(2)->seconds();

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain('Restauration terminée')
        ->assertSuccessful();

    expect(file_get_contents($this->dbFile))->toBe("SQLite format 3\0contenu-base-v1")
        ->and(file_get_contents($this->privateDir.'/photo.jpg'))->toBe('JPG-photo-v1')
        ->and(file_exists($this->privateDir.'/documents/intrus.txt'))->toBeFalse();

    $securite = dossiersSauvegarde($this->destination, 'avant-restauration-');
    expect($securite)->toHaveCount(1)
        ->and(file_get_contents($securite[0].'/base/database.sqlite'))->toBe("SQLite format 3\0contenu-base-v2-corrompu");

    $anciens = glob($this->work.'/private.avant-restauration-*', GLOB_ONLYDIR);
    expect($anciens)->toHaveCount(1)
        ->and(file_get_contents($anciens[0].'/documents/intrus.txt'))->toBe('ajout postérieur');
});

it('demande de taper le nom de la base et refuse une confirmation incorrecte', function () {
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination])->assertSuccessful();
    $dir = dossiersSauvegarde($this->destination)[0];
    file_put_contents($this->dbFile, 'etat-actuel');

    $this->artisan('patrimoine:restaurer', ['source' => $dir])
        ->expectsQuestion('Pour confirmer, tapez le nom de la base à écraser (patrimoine.sqlite)', 'oui')
        ->expectsOutputToContain('Confirmation incorrecte')
        ->assertFailed();

    expect(file_get_contents($this->dbFile))->toBe('etat-actuel');

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'autre'])->assertFailed();
    expect(file_get_contents($this->dbFile))->toBe('etat-actuel');
});

it('refuse de restaurer sans confirmation quand la commande n\'est pas interactive', function () {
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination])->assertSuccessful();
    $dir = dossiersSauvegarde($this->destination)[0];
    file_put_contents($this->dbFile, 'etat-actuel');

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--no-interaction' => true])
        ->expectsOutputToContain('Confirmation absente')
        ->assertFailed();

    expect(file_get_contents($this->dbFile))->toBe('etat-actuel');
});

it('refuse de restaurer en production sans --force', function () {
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination])->assertSuccessful();
    $dir = dossiersSauvegarde($this->destination)[0];
    file_put_contents($this->dbFile, 'etat-actuel');

    $this->app['env'] = 'production';

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain('refusée en production')
        ->assertFailed();

    expect(file_get_contents($this->dbFile))->toBe('etat-actuel');

    $this->travel(2)->seconds();
    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite', '--force' => true])->assertSuccessful();
    expect(file_get_contents($this->dbFile))->toBe("SQLite format 3\0contenu-base-v1");
});

it('refuse une sauvegarde altérée ou d\'un autre type de base sans rien modifier', function () {
    $this->artisan('patrimoine:sauvegarder', ['destination' => $this->destination])->assertSuccessful();
    $dir = dossiersSauvegarde($this->destination)[0];
    file_put_contents($this->dbFile, 'etat-actuel');

    file_put_contents($dir.'/fichiers/photo.jpg', 'altérée');
    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain('corrompue ou incomplète')
        ->assertFailed();

    file_put_contents($dir.'/fichiers/photo.jpg', 'JPG-photo-v1');
    $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);
    $manifest['database']['driver'] = 'mysql';
    file_put_contents($dir.'/manifest.json', json_encode($manifest));

    $this->artisan('patrimoine:restaurer', ['source' => $dir, '--base' => 'patrimoine.sqlite'])
        ->expectsOutputToContain('restauration refusée')
        ->assertFailed();

    $this->artisan('patrimoine:restaurer', ['source' => $this->work.'/inconnu', '--base' => 'x'])->assertFailed();

    expect(file_get_contents($this->dbFile))->toBe('etat-actuel')
        ->and(dossiersSauvegarde($this->destination, 'avant-restauration-'))->toBe([]);
});

it('lève une erreur lisible quand la base est en mémoire', function () {
    config(['database.connections.sauvegarde_test.database' => ':memory:']);

    expect(fn () => app(BackupService::class)->create($this->destination))
        ->toThrow(BackupException::class, 'en mémoire');
});

# Déploiement sur le serveur local

Cible : un serveur ou un PC dédié sur le réseau de l'Église, accessible depuis les autres postes par un navigateur à l'adresse `http://patrimoine.local`. Aucun service cloud, aucune dépendance à Internet en fonctionnement.

## Configuration recommandée

- PC ou mini-serveur dédié, 8 Go de RAM, SSD 256 Go, onduleur.
- Adresse IP fixe sur le réseau local (réservation DHCP dans le routeur).
- Nom `patrimoine.local` : entrée DNS dans le routeur si possible, sinon fichier `hosts` de chaque poste.

## Réglages PHP communs (Linux et Windows)

Pour accepter les documents de 20 Mo (RG-66), régler dans le `php.ini` **du serveur web** : `upload_max_filesize = 25M`, `post_max_size = 30M`, `memory_limit = 256M` (et `client_max_body_size 30m;` dans Nginx). Les valeurs par défaut de PHP (2 Mo et 8 Mo) bloqueraient l'envoi. Sous WAMP, PHP en ligne de commande et PHP d'Apache n'ont pas le même fichier : régler les deux (voir plus bas).

Extensions exigées : `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`, `zip`. La commande `php artisan patrimoine:verifier` contrôle cette liste (celle de `composer.json` plus celles de Laravel et de Filament), les droits d'écriture, la clé et la base.

## Option A : Linux (recommandée)

Ubuntu Server LTS, Nginx, PHP-FPM 8.3 ou plus avec les extensions ci-dessus, MySQL 8 ou MariaDB 10.11+. Le script `install.ps1` est propre à Windows : sous Linux, les étapes sont celles-ci.

1. Préparer sur une machine connectée : `composer install --no-dev --optimize-autoloader` et `npm ci && npm run build`. Le paquet livré contient `vendor/` et `public/build/` : aucune installation depuis Internet sur le site.
2. Copier l'application dans `/var/www/patrimoine`, créer `.env` depuis `.env.example` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=http://patrimoine.local`, `APP_TIMEZONE=Africa/Brazzaville`, base de données).
3. `php artisan key:generate`, puis `php artisan patrimoine:installer` (migrations, référentiels, premier administrateur : même commande que sous Windows), puis `php artisan optimize`. Pas de `storage:link` : les fichiers sont privés et servis après contrôle des droits.
4. Tâche planifiée : `* * * * * php /var/www/patrimoine/artisan schedule:run`.
5. Sauvegarde : voir la section dédiée, la commande est identique.

## Option B : Windows avec WAMP

Procédure testée sur WAMP 3.3 (Apache, PHP 8.3 ou 8.4, MariaDB ou MySQL). L'installation tient en un script, `install.ps1`, relançable sans dégât.

### 1. Prérequis (une seule fois)

1. **WAMP** installé hors ligne (installateur copié sur clé USB), avec **PHP 8.3 ou plus** actif (icône WAMP > PHP > Version) et MariaDB ou MySQL démarré (icône verte).
2. **Extensions PHP** : icône WAMP > PHP > Extensions PHP : cocher `gd`, `intl`, `zip`, `bcmath`, `mbstring`, `pdo_mysql`, `fileinfo`, `curl`, `openssl`, `xml` (cochées par défaut pour la plupart). `iconv` est intégrée à PHP sous Windows.
3. **php.ini** : icône WAMP > PHP > php.ini (celui d'Apache) puis régler `upload_max_filesize = 25M`, `post_max_size = 30M`, `memory_limit = 256M`, `date.timezone = Africa/Brazzaville`. Le PHP en ligne de commande lit un autre fichier (`C:\wamp64\bin\php\php8.x.y\php.ini`) : y reporter `memory_limit` et les `extension=...` ci-dessus. Redémarrer WAMP après chaque changement.
4. **Base de données** : rien à créer à la main si le compte MySQL (par défaut `root`, sans mot de passe sous WAMP) a le droit de créer une base : le script crée la base `patrimoine` (utf8mb4) si elle n'existe pas. Sur un serveur réel, créer plutôt un utilisateur dédié, avec un mot de passe, limité à cette base :
   ```sql
   CREATE DATABASE patrimoine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'patrimoine'@'127.0.0.1' IDENTIFIED BY '<mot de passe choisi>';
   GRANT ALL PRIVILEGES ON patrimoine.* TO 'patrimoine'@'127.0.0.1';
   ```
5. **Où copier l'application** : hors du dossier `www` de WAMP, par exemple `C:\patrimoine` (le dossier d'accès public est `public\`). Le paquet livré contient `vendor\` et `public\build\` ; sinon, il faut Composer, Node 20.19+ ou 22+ (de préférence Node 22 ou 24 : le npm 10 de Node 20 est instable) et une connexion Internet pour cette seule étape.

### 2. Installation

Ouvrir PowerShell dans `C:\patrimoine` puis :

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\install.ps1 -Url http://patrimoine.local -DbNom patrimoine -DbUtilisateur patrimoine
```

Le script, dans l'ordre :

1. cherche PHP (option `-Php C:\wamp64\bin\php\php8.3.x\php.exe` pour en imposer un), refuse une version inférieure à 8.3 ;
2. crée `.env` à partir de `.env.example` s'il n'existe pas (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, base), demande le mot de passe MySQL sans l'afficher, génère `APP_KEY` si elle est vide. **Un `.env` existant n'est jamais modifié.** Pour un essai sans MySQL : `-Base sqlite` ;
3. compile l'interface si `public\build` est absent (`-SansFront` pour l'ignorer) ;
4. lance `php artisan patrimoine:installer` : contrôles (PHP, extensions, dossiers, clé), base (créée si absente), migrations, référentiels de base (rôles et droits, états, catégories, types de document, formats d'étiquettes ; les valeurs déjà modifiées ne sont jamais écrasées), puis **création du premier administrateur** : identifiant, prénom, nom, mot de passe saisi deux fois sans écho (12 caractères minimum, lettres et chiffres). Le mot de passe n'est ni écrit dans un fichier, ni affiché, ni passé en argument. Si un administrateur existe déjà, aucun compte n'est créé ;
5. lance `php artisan optimize` (met en cache configuration, routes, vues).

Options utiles :

- `-Demo` charge en plus la démonstration (comptes à mot de passe connu : **jamais sur le serveur réel**). Un `.env` neuf est alors écrit en `APP_ENV=local` (la démonstration est refusée en production). La commande la refuse aussi dès que la base contient un compte ou des données (la démonstration réécrit le compte `admin` : elle ne peut donc jamais toucher un vrai administrateur) ; pour la recharger, repartir d'une base vide.
- La tâche Windows de la section 5 est enregistrée d'office (PowerShell en administrateur ; sinon un avertissement donne la marche à suivre). `-SansTachePlanifiee` la refuse ; avec `-Demo`, elle n'est créée que si `-TachePlanifiee` est passé.
- `-MotDePasseStdin -Identifiant ... -Prenom ... -Nom ...` pour un déploiement scripté. Le script lit alors toute l'entrée standard, **une valeur par ligne, dans cet ordre** : 1) le mot de passe de la base, seulement si `.env` est créé et que la base n'est pas SQLite ; 2) le mot de passe du premier administrateur, seulement si aucun administrateur n'existe. Exemple : `"MotDePasseBase`nMotDePasseAdmin" | .\install.ps1 -MotDePasseStdin -Identifiant admin`. Éviter les caractères accentués dans ces mots de passe (le tube de Windows PowerShell 5.1 peut les altérer).
- Après toute modification ultérieure de `.env`, relancer `php artisan optimize` : la configuration est mise en cache.

À tout moment : `php artisan patrimoine:verifier` rejoue les contrôles sans rien modifier (les valeurs `upload_max_filesize` et `post_max_size` affichées sont celles de PHP en ligne de commande ; vérifier celles d'Apache dans le php.ini de WAMP).

Structure de l'Église : l'installation ne crée **aucun** site, département ni local (aucune donnée propre à l'Église n'est présupposée). Après la première connexion, l'administrateur les saisit dans Structure (sites, départements, services, locaux), puis les utilisateurs.

### 3. Apache : hôte virtuel `patrimoine.local`

Icône WAMP > Vos VirtualHosts > Gestion VirtualHost, ou à la main dans `C:\wamp64\bin\apache\apache2.x.y\conf\extra\httpd-vhosts.conf` :

```apache
<VirtualHost *:80>
    ServerName patrimoine.local
    DocumentRoot "C:/patrimoine/public"
    <Directory "C:/patrimoine/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Vérifier que `mod_rewrite` est activé (WAMP > Apache > Modules Apache). Ne pas servir `C:\patrimoine` lui-même : seul `public\` doit être exposé (`.env`, `storage\` et `vendor\` restent hors du web).

### 4. Accès depuis le réseau local

1. **Adresse IP fixe** du poste serveur : réservation DHCP dans le routeur (ou IP statique Windows).
2. **Nom `patrimoine.local`** : de préférence une entrée DNS dans le routeur. Sinon, sur chaque poste, ajouter `192.168.1.50 patrimoine.local` (l'IP du serveur) à `C:\Windows\System32\drivers\etc\hosts` (éditeur lancé en administrateur). Sur le serveur lui-même : `127.0.0.1 patrimoine.local`.
3. **Pare-feu Windows** du serveur : autoriser le port 80 en entrée, profil Privé uniquement (Pare-feu Windows > Règles de trafic entrant > Nouvelle règle > Port > TCP 80 > Privé). Ne pas ouvrir le port 3306 (MySQL) au réseau : la base doit rester sur `127.0.0.1`.
4. WAMP doit être « en ligne » (Put Online) pour accepter les autres postes.
5. Les postes utilisent un navigateur récent, adresse en favori ; `APP_URL` du `.env` doit être cette même adresse.

### 5. Planificateur Windows (`schedule:run` chaque minute)

Le planificateur de Laravel (alertes et rappels) ne tourne que si Windows l'appelle chaque minute. `install.ps1` enregistre cette tâche d'office ; sinon, à la main (PowerShell en administrateur) :

```powershell
$php = 'C:\wamp64\bin\php\php8.4.0\php.exe'
$action = New-ScheduledTaskAction -Execute $php -Argument '"C:\patrimoine\artisan" schedule:run' -WorkingDirectory 'C:\patrimoine'
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName 'Patrimoine - planificateur' -Action $action -Trigger $trigger -Principal $principal -Force
```

L'exécutable et ses arguments sont séparés, ce qui garde correctement cités les chemins contenant des espaces (une commande `schtasks /TR` unique les casse). Remplacer le chemin de PHP par celui de la version utilisée (`php.exe` en ligne de commande). Vérification : `Get-ScheduledTask 'Patrimoine - planificateur'`, puis `php artisan schedule:list`. Aucun service de file d'attente n'est requis à ce jour : les exports et PDF sont produits dans la requête (`QUEUE_CONNECTION=database` reste prêt si un lot ultérieur en a besoin).

### 6. Démarrage automatique

WAMP > Préférences : cocher « Lancer WAMP au démarrage de Windows » et « Mettre en ligne au démarrage ». Vérifier au redémarrage que l'icône est verte et que `http://patrimoine.local` répond.

## Postes utilisateurs

Un navigateur récent (Chrome, Edge ou Firefox), l'adresse en favori. Douchette USB en mode clavier avec suffixe Entrée. Impression des étiquettes depuis le PDF généré, à l'échelle 100 %, sans marges ajoutées par le navigateur.

## Messages d'erreur à connaître

Pages françaises du lot 12 : 403 (accès refusé), 404, 419 (session expirée ; 30 minutes d'inactivité par défaut, réglables dans Administration > Paramètres > Sécurité, sans toucher à `.env`), 429, 500, 503 (maintenance). Dans le panneau, une coupure du réseau ou l'arrêt du serveur affiche « Connexion au serveur perdue » avec un bouton Réessayer : les saisies restent à l'écran, ne pas actualiser la page. En cas de 500, le détail technique est dans `storage\logs\laravel.log`.

## Sauvegarde (option Q20, hors V1)

Commande manuelle, à lancer chaque semaine (ou avant toute mise à jour) :

```powershell
php artisan patrimoine:sauvegarder E:\Sauvegardes
```

- La destination doit **exister** et être accessible en écriture (disque externe branché, partage réseau). Sinon, la commande refuse. Elle refuse aussi un dossier situé dans le dossier de l'application (public, fichiers privés, etc.).
- Elle crée un dossier daté `E:\Sauvegardes\patrimoine-20261028-143015\` contenant `base\dump.sql` (export MySQL ou MariaDB par `mysqldump`, trouvé automatiquement dans WAMP ; sinon `--bin=C:\wamp64\bin\mariadb\mariadb11.5.2\bin`), `fichiers\` (copie de `storage\app\private` : photos, documents) et `manifest.json` (taille et somme SHA-256 de chaque fichier).
- La sauvegarde est **relue et contrôlée** avant d'être annoncée réussie. En cas d'échec, le dossier partiel est retiré et la commande se termine en erreur : ne jamais considérer comme sauvegarde un dossier sans le message « Sauvegarde terminée et contrôlée ».
- Elle **ne contient pas** le fichier `.env` (clé de l'application, mot de passe de la base). En garder une copie à part. Elle n'est pas chiffrée : ranger le disque externe en lieu sûr.
- Aucune ancienne sauvegarde n'est supprimée automatiquement : faire le ménage à la main (garder au moins les quatre dernières, plus la dernière de chaque mois).

### Restauration

Prévue pour un incident (base corrompue, disque remplacé, mauvaise manipulation). **Elle remplace les données actuelles.**

1. Sur un nouveau poste seulement : refaire d'abord l'installation (sections 1 à 4, sans `-Demo`) avec le `.env` conservé.
2. Mettre l'application en maintenance : `php artisan down`. **La commande refuse de restaurer si l'application n'est pas en maintenance** (personne ne doit écrire pendant l'opération).
3. Lancer (la source est le dossier daté) :
   ```powershell
   php artisan patrimoine:restaurer E:\Sauvegardes\patrimoine-20261028-143015
   ```
   La commande contrôle d'abord l'intégrité de la sauvegarde (taille et SHA-256 de chaque fichier) et le type de base, refuse si quelque chose ne correspond pas, affiche la date de la sauvegarde, puis demande de **taper le nom de la base à écraser**. Sans terminal : `--base=<nom>` ; en production, `--force` est exigé en plus.
4. Avant d'écrire quoi que ce soit, elle sauvegarde l'état actuel à côté de la source (`avant-restauration-AAAAMMJJ-HHMMSS`) et s'arrête si cette sauvegarde échoue. Les fichiers de la sauvegarde sont copiés et recontrôlés dans un dossier de transit, puis échangés avec `storage\app\private` (l'ancien dossier est renommé `private.avant-restauration-...`, jamais supprimé). Seuls les fichiers listés au manifeste sont restaurés ; un fichier en trop dans la sauvegarde la fait refuser. Si l'import de la base échoue en cours de route, la commande rejoue automatiquement la sauvegarde de sécurité et remet les anciens fichiers ; si ce rejeu échoue aussi, elle affiche le message « Base partiellement restaurée » et la commande exacte à lancer pour revenir à l'état précédent.
5. `php artisan optimize:clear`, `php artisan optimize`, `php artisan up`, puis ouvrir l'application et contrôler un bien récent et une pièce jointe.

À savoir : restaurer vers la **même version** de l'application que la sauvegarde. Les tables qui n'existent pas dans la sauvegarde (version plus récente) ne sont pas supprimées ; lancer `php artisan migrate --force` au besoin. Pour revenir en arrière après une restauration, restaurer le dossier `avant-restauration-...`.

Si la restauration automatique est impossible (outil `mysql` introuvable), procédure manuelle : créer la base vide, importer `base\dump.sql` (`mysql -u utilisateur -p patrimoine < dump.sql`, ou phpMyAdmin pour un petit fichier), puis copier le contenu de `fichiers\` dans `storage\app\private\`.

## Mise à jour

Nouveau paquet préparé sur une machine connectée (avec `vendor\` et `public\build\`), copié sur le serveur :

1. `php artisan down` (plus personne n'écrit pendant la sauvegarde) ;
2. `php artisan patrimoine:sauvegarder E:\Sauvegardes` et vérifier le message de contrôle ;
3. remplacer les fichiers de l'application **sauf** `.env` et `storage\` ;
4. `php artisan migrate --force` puis `php artisan optimize` (relancer `install.ps1` est aussi sans risque) ;
5. `php artisan up`, et ouvrir l'application pour vérifier.

En cas de problème après migration : l'application est encore en maintenance, remettre les anciens fichiers, restaurer la sauvegarde du point 2 (section Restauration), puis `php artisan optimize:clear`, `php artisan optimize` et `php artisan up`.

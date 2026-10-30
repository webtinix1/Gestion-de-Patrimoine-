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
4. **Base de données** : sur le serveur réel, ne pas utiliser le compte `root` (sans mot de passe par défaut sous WAMP). `install.ps1` **refuse un mot de passe MySQL vide** (sauf avec `-Demo`) et **avertit** si le compte est `root`. Créer un utilisateur dédié, avec un mot de passe, limité à la seule base (console MySQL ou MariaDB, en `root`) :
   ```sql
   CREATE DATABASE patrimoine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'patrimoine'@'127.0.0.1' IDENTIFIED BY '<mot de passe choisi>';
   CREATE USER 'patrimoine'@'localhost' IDENTIFIED BY '<mot de passe choisi>';
   GRANT ALL PRIVILEGES ON patrimoine.* TO 'patrimoine'@'127.0.0.1';
   GRANT ALL PRIVILEGES ON patrimoine.* TO 'patrimoine'@'localhost';
   FLUSH PRIVILEGES;
   ```
   Puis lancer `install.ps1 -DbUtilisateur patrimoine`. Vérifier aussi que la base n'écoute que sur la machine : `bind-address=127.0.0.1` dans la section `[mysqld]` de `my.ini` (WAMP > MySQL ou MariaDB > fichier my.ini), puis redémarrer le service ; `SHOW VARIABLES LIKE 'bind_address';` doit répondre `127.0.0.1`.

   **Verrou du journal (RG-70)** : l'installation pose des déclencheurs qui empêchent de modifier ou d'effacer le journal des opérations, même par un accès direct à la base. MySQL 8 et 9 ont le journal binaire actif par défaut. Dans ce cas, un compte sans privilège SUPER, comme `patrimoine`, ne peut pas créer de déclencheur. Ajouter dans la même section `[mysqld]` de `my.ini` :
   ```ini
   log_bin_trust_function_creators=1
   ```
   puis redémarrer le service. Sans ce réglage, l'installation va quand même à son terme. Elle affiche un avertissement, et le journal reste protégé par l'application seule. Pour poser les déclencheurs ensuite, une fois le réglage fait : `php artisan patrimoine:verifier --reparer`. La ligne « Journal verrouillé en base » doit afficher OK.
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
- `-ReseauAutorise 192.168.1.0/24` borne le pare-feu (section 4, défaut `LocalSubnet`) ; `-CompteApache '<compte>'` indique le compte qui fait tourner Apache (défaut `SYSTEM`, ce que fait le service WAMP installé par défaut) ; `-SansPareFeu` et `-SansDurcissement` ignorent la règle de pare-feu et le durcissement des droits (section 6) ; avec `-Demo`, le pare-feu n'est créé que si `-PareFeu` est passé.
- Une `-Url` en `https://` écrit aussi `SESSION_SECURE_COOKIE=true` dans le `.env` neuf (section 8).
- La tâche Windows de la section 5 est enregistrée d'office (PowerShell en administrateur ; sinon un avertissement donne la marche à suivre). `-SansTachePlanifiee` la refuse ; avec `-Demo`, elle n'est créée que si `-TachePlanifiee` est passé.
- `-MotDePasseStdin -Identifiant ... -Prenom ... -Nom ...` pour un déploiement scripté. Le script lit alors toute l'entrée standard, **une valeur par ligne, dans cet ordre** : 1) le mot de passe de la base, seulement si `.env` est créé et que la base n'est pas SQLite ; 2) le mot de passe du premier administrateur, seulement si aucun administrateur n'existe. Exemple : `"MotDePasseBase`nMotDePasseAdmin" | .\install.ps1 -MotDePasseStdin -Identifiant admin`. Le premier administrateur créé ainsi devra changer son mot de passe à la première connexion (saisi à la main, il ne l'est pas). Éviter les caractères accentués dans ces mots de passe (le tube de Windows PowerShell 5.1 peut les altérer).
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
3. **Pare-feu Windows** du serveur : `install.ps1` (en administrateur) crée la règle « Patrimoine - HTTP » : TCP en entrée, port tiré de `-Url` (80 en http, 443 en https), **profil Privé uniquement**, **limitée au réseau `-ReseauAutorise`** (par défaut `LocalSubnet`, le sous-réseau du serveur ; préférer la plage exacte, par exemple `192.168.1.0/24`). La règle est supprimée et recréée à chaque passage ; en cas d'échec, un avertissement donne la commande. À la main : `New-NetFirewallRule -DisplayName 'Patrimoine - HTTP' -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow -Profile Private -RemoteAddress 192.168.1.0/24`. Ne jamais ouvrir le port 80 sans borne de réseau, ni le port 3306 (MySQL) : la base reste sur `127.0.0.1`.
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

### 6. Droits NTFS du dossier d'installation

La tâche de la section 5 s'exécute en **SYSTEM** sur le dossier de l'application : tout compte pouvant y écrire (un fichier PHP ou `artisan` modifié) obtiendrait les droits SYSTEM. Quand la tâche est enregistrée, `install.ps1` (sauf `-SansDurcissement`) accorde : contrôle total à SYSTEM et aux Administrateurs, lecture et exécution aux Utilisateurs, puis retire l'héritage du dossier. `storage`, `bootstrap\cache` et `.env` sont fermés aux autres comptes (SYSTEM et Administrateurs, plus le compte Apache : Modifier sur `storage` et `bootstrap\cache`, lecture sur `.env`). Les SID remplacent les noms de comptes : le résultat est le même quelle que soit la langue de Windows. Le service WAMP tourne en `SYSTEM` par défaut : sans autre réglage, `-CompteApache` reste `SYSTEM`. Si Apache est lancé sous un autre compte, le passer à `install.ps1`.

En cas d'avertissement (PowerShell non administrateur), à lancer à la main en administrateur (remplacer `<compte Apache>` ; inutile s'il s'agit de SYSTEM) :

```powershell
icacls C:\patrimoine /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-32-545:(OI)(CI)RX'
icacls C:\patrimoine /inheritance:r
icacls C:\patrimoine\storage /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '<compte Apache>:(OI)(CI)M'
icacls C:\patrimoine\bootstrap\cache /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' '<compte Apache>:(OI)(CI)M'
icacls C:\patrimoine\.env /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' '<compte Apache>:R'
```

Après une mise à jour qui remplace des fichiers, relancer `install.ps1` (sans risque) ou vérifier les droits avec `icacls C:\patrimoine`.

### 7. Démarrage automatique

WAMP > Préférences : cocher « Lancer WAMP au démarrage de Windows » et « Mettre en ligne au démarrage ». Vérifier au redémarrage que l'icône est verte et que `http://patrimoine.local` répond.

### 8. HTTPS (recommandé) et risque accepté en HTTP

En HTTP, les mots de passe et les cookies de session circulent en clair sur le réseau local : un poste ou un équipement compromis du réseau peut les lire. **Recommandation** : servir l'application en HTTPS avec un certificat interne.

1. Générer un certificat pour `patrimoine.local` (autorité interne de l'Église, ou certificat auto-signé importé dans les « Autorités de certification racines de confiance » de chaque poste). Placer `patrimoine.crt` et `patrimoine.key` hors de `public\` (par exemple `C:\wamp64\ssl`).
2. Activer `mod_ssl` et `mod_socache_shmcb` (WAMP > Apache > Modules Apache) et ajouter un hôte virtuel sur le port 443 :
   ```apache
   <VirtualHost *:443>
       ServerName patrimoine.local
       DocumentRoot "C:/patrimoine/public"
       SSLEngine on
       SSLCertificateFile "C:/wamp64/ssl/patrimoine.crt"
       SSLCertificateKeyFile "C:/wamp64/ssl/patrimoine.key"
       <Directory "C:/patrimoine/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
   Le port 80 peut rediriger vers 443 (`Redirect permanent / https://patrimoine.local/`).
3. Dans `.env` : `APP_URL="https://patrimoine.local"` et `SESSION_SECURE_COOKIE=true` (le cookie de session n'est alors jamais envoyé en HTTP). `install.ps1 -Url https://patrimoine.local` écrit ces deux valeurs dans un `.env` neuf et ouvre le port 443 au lieu du 80. Pour un `.env` existant, les modifier à la main puis `php artisan optimize`.

Si l'Église reste en HTTP, ce risque est **accepté** : le réseau doit rester privé (pare-feu borné à `-ReseauAutorise`, Wi-Fi protégé, pas d'invité sur le même réseau), et `SESSION_SECURE_COOKIE` doit rester absent ou à `false` (à `true` en HTTP, la connexion est impossible).

## Option C : Docker

Une seule image contient l'application (Nginx et PHP 8.4-FPM, interface déjà compilée). `docker/compose.yaml` lance trois conteneurs :

- `app` : l'application, publiée sur le port `APP_PORT` (8080 par défaut).
- `scheduler` : le planificateur (`schedule:work`), qui remplace la tâche planifiée Windows.
- `db` : MariaDB 11.4 LTS, l'un des deux moteurs acceptés, également testé par la CI. Les connexions à la base sont chiffrées en TLS avec un certificat d'une autorité interne, créé au premier lancement par le service `certificats`. Les outils de sauvegarde vérifient ce certificat.

Les données vivent dans des volumes Docker : `base` (MySQL), `fichiers` (photos et documents, `storage/app`) et `journaux`. L'image ne contient aucun secret. Elle convient à un serveur Linux ou à un poste Windows avec Docker Desktop.

**Installation**

1. Copier `docker/.env.example` en `docker/.env`, puis remplir :
   - `DB_PASSWORD` et `DB_ROOT_PASSWORD` : deux mots de passe différents ;
   - `APP_URL` : l'adresse que les postes ouvriront ;
   - `APP_KEY` : générée par la commande ci-dessous, puis copiée dans le fichier.
   ```
   docker compose -f docker/compose.yaml build
   docker compose -f docker/compose.yaml run --rm --no-deps app php artisan key:generate --show
   ```
   **Conserver `docker/.env` en lieu sûr** : il contient la clé qui signe les sauvegardes (QL13-3).
2. Démarrer :
   ```
   docker compose -f docker/compose.yaml up -d
   ```
   Au démarrage, le conteneur `app` :
   - attend la base ;
   - passe les migrations et met les caches à jour ;
   - pose le verrou du journal. Le journal binaire étant désactivé dans `db`, aucun réglage MySQL n'est nécessaire.
3. Créer le premier administrateur et les référentiels de base. Le mot de passe est demandé sans être affiché :
   ```
   docker compose -f docker/compose.yaml exec -u www-data app php artisan patrimoine:installer
   ```
   Pour un poste d'essai, ajouter `--demo` pour la démonstration. Les comptes qu'elle crée ont un mot de passe connu.
4. Vérifier :
   ```
   docker compose -f docker/compose.yaml exec -u www-data app php artisan patrimoine:verifier
   ```

**Sans Internet chez le client** : construire l'image sur un poste connecté et l'exporter avec MySQL :
```
docker save patrimoine:latest mariadb:11.4 -o patrimoine-images.tar
```
Sur le serveur, après avoir copié le dossier du projet (au minimum `docker/`) :
```
docker load -i patrimoine-images.tar
```
Lancer ensuite `up -d` **sans** `--build`.

**Exploitation**

- **Sauvegarde** : le dossier `BACKUP_DIR` du serveur est monté sur `/sauvegardes` dans le conteneur. Lancer :
  ```
  docker compose -f docker/compose.yaml exec -u www-data app php artisan patrimoine:sauvegarder /sauvegardes
  ```
  Le client MariaDB inclus fournit `mysqldump`.
- **Restauration** :
  1. `exec -u www-data app php artisan down`
  2. `exec -u www-data app php artisan patrimoine:restaurer /sauvegardes/patrimoine-AAAAMMJJ-HHMMSS --force`
  3. `exec -u www-data app php artisan optimize:clear`, puis `exec -u www-data app php artisan optimize`
  4. `exec -u www-data app php artisan up`

  Ces commandes se lancent depuis PowerShell, ou depuis un terminal Linux. Sous Git Bash, préfixer avec `MSYS_NO_PATHCONV=1` : sinon `/sauvegardes` est converti en chemin Windows.

  La signature est vérifiée avec l'`APP_KEY` de `docker/.env`.
- **Mise à jour** : remplacer le code (ou charger la nouvelle image), puis lancer `docker compose -f docker/compose.yaml up -d --build`. Les migrations passent au démarrage, et les volumes conservent la base et les fichiers.
- **Journaux** : `docker compose -f docker/compose.yaml logs -f app`.
- **HTTPS** : mettre un proxy inverse devant le port publié (Caddy, Traefik ou Nginx, avec un certificat interne), puis passer `APP_URL` en `https://…` et `SESSION_SECURE_COOKIE=true`.
- **Pare-feu** : n'ouvrir que `APP_PORT`, limité au réseau local. La base n'est jamais publiée hors de Docker.

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
- `manifest.json` est **signé** (HMAC-SHA256 avec `APP_KEY`, fichier `manifest.sig` à côté) : la restauration refuse une sauvegarde dont le manifeste a été modifié ou dont la signature manque.
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

**Signature et `APP_KEY`.** La restauration vérifie la signature du manifeste avec l'`APP_KEY` du poste. Pour restaurer sur un **nouveau poste**, reprendre d'abord l'`APP_KEY` d'origine dans le `.env` conservé (d'où la copie du `.env` à garder à part), puis `php artisan optimize`. Si cette clé est perdue, ou pour une sauvegarde faite avant l'introduction de la signature, ajouter `--sans-signature` : la commande affiche un avertissement et ne vérifie plus l'authenticité du manifeste (l'intégrité de chaque fichier reste contrôlée par SHA-256). N'utiliser cette option que pour un dossier dont on maîtrise la provenance.

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

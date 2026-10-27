# Déploiement sur le serveur local

Cible : un serveur ou un PC dédié sur le réseau de l'Église, accessible depuis les autres postes par un navigateur à l'adresse `http://patrimoine.local`. Aucun service cloud, aucune dépendance à Internet en fonctionnement.

## Configuration recommandée

- PC ou mini-serveur dédié, 8 Go de RAM, SSD 256 Go, onduleur.
- Adresse IP fixe sur le réseau local (réservation DHCP dans le routeur).
- Nom `patrimoine.local` : entrée DNS dans le routeur si possible, sinon fichier `hosts` de chaque poste.

## Option A : Linux (recommandée)

Ubuntu Server LTS, Nginx, PHP-FPM 8.3 ou plus avec les extensions `intl`, `gd`, `zip`, `bcmath`, `mbstring`, `pdo_mysql`, MySQL 8.

Pour accepter les documents de 20 Mo (RG-66), régler dans `php.ini` : `upload_max_filesize = 25M` et `post_max_size = 30M` (et `client_max_body_size 30m;` dans Nginx). Les valeurs par défaut de PHP (2 Mo et 8 Mo) bloqueraient l'envoi. Ces réglages valent aussi pour l'option Windows.

1. Préparer sur une machine connectée : `composer install --no-dev --optimize-autoloader` et `npm ci && npm run build`. Le paquet livré contient `vendor/` et `public/build/` : aucune installation depuis Internet sur le site.
2. Copier l'application dans `/var/www/patrimoine`, configurer `.env` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=http://patrimoine.local`, `APP_TIMEZONE=Africa/Brazzaville`, base de données).
3. `php artisan key:generate`, `php artisan migrate --force`, `php artisan db:seed --class=ReferenceSeeder --force`, puis `php artisan optimize`. Pas de `storage:link` : les fichiers sont privés et servis après contrôle des droits.
4. Tâche planifiée : `* * * * * php /var/www/patrimoine/artisan schedule:run` (alertes, rappels).
5. File d'attente pour les gros PDF et exports : pilote `database`, worker en service systemd.

## Option B : Windows

Laragon (Apache ou Nginx, PHP, MySQL) installé hors ligne, lancement automatique au démarrage, tâche planifiée Windows pour `schedule:run` toutes les minutes, worker de file lancé au démarrage.

## Postes utilisateurs

Un navigateur récent (Chrome, Edge ou Firefox), l'adresse en favori. Douchette USB en mode clavier avec suffixe Entrée. Impression des étiquettes depuis le PDF généré, à l'échelle 100 %, sans marges ajoutées par le navigateur.

## Sauvegarde (option, hors V1)

Commande `php artisan patrimoine:sauvegarder <dossier>` : export de la base et archive des fichiers vers un disque externe, avec date dans le nom. À lancer chaque semaine. La restauration est documentée et testée à la livraison.

## Mise à jour

Nouveau paquet préparé sur une machine connectée, copié sur le serveur, puis `php artisan down`, remplacement des fichiers (hors `.env` et `storage/`), `php artisan migrate --force`, `php artisan optimize`, `php artisan up`. Toujours faire une sauvegarde avant.

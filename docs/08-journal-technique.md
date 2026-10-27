# Journal technique

Tenu par Claude Code à la fin de chaque lot, relu par le développeur. Une entrée par lot, la plus récente en haut.

## Versions installées

| Composant | Version | Date |
|---|---|---|
| PHP | 8.4.0 sur le poste de développement (WAMP) ; dépendances résolues pour PHP 8.3 (`config.platform.php`) | 27/10/2026 |
| Laravel | 13.38.0 (squelette `laravel/laravel` 13.11) | 27/10/2026 |
| Filament | 5.11.0 | 27/10/2026 |
| Livewire | 4.4.8 | 27/10/2026 |
| Pest | 4.7.8 | 27/10/2026 |
| Larastan / PHPStan | 3.13.2, niveau 6 | 27/10/2026 |
| Laravel Pint | 1.32.1 | 27/10/2026 |
| Node / npm (build du thème) | Node 24.22 / npm 11.19 | 27/10/2026 |
| MySQL / MariaDB | Non installé par le lot 0 : `.env.example` cible MySQL ; WAMP du poste fournit MySQL 9.1 et MariaDB 11.5 | 27/10/2026 |

Compatibilité vérifiée sur Packagist le 27/10/2026 : Laravel 13 exige PHP ^8.3, Filament 5 PHP ^8.2 et Livewire ^4, Pint PHP ^8.3. Pest 5 (dernière version) exige PHP ^8.4 : on reste sur Pest 4 pour que le projet s'installe sur un poste en PHP 8.3, comme le prévoit `CLAUDE.md`.

## Lot 0 · Socle · 27/10/2026

- **Fait** :
  - Projet Laravel 13 créé dans un dossier temporaire puis déplacé à la racine sans toucher à `CLAUDE.md`, `README.md`, `docs/`, `.claude/` ni `tests/Fixtures/`. Les fichiers `CLAUDE.md`, `AGENTS.md` et `README.md` du squelette ont été écartés. `.gitignore` du squelette fusionné (le dépôt n'en avait pas).
  - Panneau Filament unique `/admin` (`AdminPanelProvider`), `/` redirige vers `/admin`. Groupes de navigation de `docs/04` déclarés dans l'ordre.
  - Langue `fr` (avec repli `fr`), Faker `fr_FR`, fuseau `Africa/Brazzaville` (`APP_TIMEZONE`). Fichiers `lang/fr` (validation, auth, pagination, passwords) écrits à la main ; Filament fournit ses propres traductions françaises.
  - Connexion par identifiant : page `App\Filament\Pages\Auth\Login` (champ `username`, message d'échec « Identifiant ou mot de passe incorrect. »). Un compte désactivé est refusé (`canAccessPanel`, RG-84).
  - Table `users` au modèle de `docs/03` : `first_name`, `last_name`, `username` unique, `email` facultatif, `phone`, `job_title`, `must_change_password`, `is_active`, `last_login_at`, `last_login_ip`, `failed_attempts`, `locked_until`. Seules les colonnes existent : blocage, inactivité et mot de passe provisoire (RG-85) sont au lot 2.
  - Thème : palettes fixées dans `App\Filament\Theme`, avec la primaire `#0B5D6B` en nuance 600, l'accent `#F2B544` et les statuts (fond 50, texte 600) de `docs/04`. Le CSS Vite `resources/css/filament/admin/theme.css` ajoute les badges à angles droits en capitales de 11 px, les cartes à 12 px sans ombre, le menu latéral `#262626` avec repère accent, les titres de page en 800, les sections en capitales espacées et les zones cliquables de 44 px. Mode sombre désactivé.
  - Polices Archivo (variable) et Martian Mono (400, 500, 700) en `woff2` dans `public/fonts`, sous-ensembles latin et latin étendu, licences OFL jointes ; déclarées dans `public/fonts/fonts.css` et branchées par `LocalFontProvider` (texte et `monoFont`). La classe CSS `pat-mono` est prévue pour les numéros et montants.
  - Seeders : `DatabaseSeeder` appelle `ReferenceSeeder` (vide pour l'instant, chargé aussi en production) puis `DemoSeeder` (hors production) qui crée le compte administrateur de démonstration ; identifiant et mot de passe dans `DemoSeeder`.
  - Outillage : Pest (avec les plugins Laravel et Livewire), Pint (`pint.json`, preset laravel), Larastan (`phpstan.neon`, niveau 6). 34 tests (275 assertions), Pint et PHPStan au vert.
- **Décisions** (et pourquoi) :
  - Le fournisseur d'avatars par défaut de Filament charge les images depuis ui-avatars.com : remplacé par `InitialsAvatarProvider`, qui génère un SVG local en data URI.
  - Le fournisseur de polices par défaut d'un panneau personnalisé est Bunny Fonts (CDN) : `LocalFontProvider` est imposé explicitement. La configuration Vite du squelette appelait aussi Bunny Fonts : supprimée, avec `welcome.blade.php`, `app.css` et `app.js` (pas de site public).
  - Palettes de couleurs écrites à la main : la palette générée par `Color::hex('#0B5D6B')` est trop vive et l'algorithme de contraste de Filament retenait la nuance 400 (bouton cyan clair). Un test vérifie que les boutons principaux utilisent la nuance 600.
  - `public/css/filament`, `public/js/filament` et `public/fonts/filament` sont ignorés par Git : ils sont republiés par `php artisan filament:upgrade` (ajouté au `post-autoload-dump`).
  - `optionalDependencies` `@laravel/multiplex` et `concurrently` retirés du `package.json` : inutiles ici, et `npm install` échouait. npm 10.8 (fourni avec Node 20) plante sur ce projet (« Cannot read properties of null (reading 'edgesOut') ») ; le build passe avec Node 24 / npm 11. **Utiliser Node 22 ou plus.**
  - `.env.example` cible MySQL (base `patrimoine`) ; les tests tournent sur SQLite en mémoire (`phpunit.xml`).
  - Aucun paquet hors liste installé (Pest, ses plugins, Larastan et Pint font partie de la stack prévue).
- **Écarts par rapport aux docs** : `CLAUDE.md` indique Node 20+ ; dans les faits, npm 10 de Node 20 échoue (voir ci-dessus). Les vues d'erreur et la page de profil restent celles de Filament (lots 2 et 12).
- **Questions ajoutées** : aucune.
- **À tester à la main** :
  - Couper le Wi-Fi, lancer l'application et vérifier que la connexion, le tableau de bord et les polices fonctionnent (critère 1 du lot 0, non coché).
  - Connexion avec le compte de démonstration ; échec avec un mauvais mot de passe (message en français).
  - Aspect : bouton « Connexion » en bleu pétrole `#0B5D6B`, menu latéral foncé avec repère jaune, police Archivo.

## Modèle d'entrée

### Lot N · titre · jj/mm/aaaa

- **Fait** :
- **Décisions** (et pourquoi) :
- **Écarts par rapport aux docs** :
- **Questions ajoutées** (renvoi vers `06-questions-ouvertes.md`) :
- **À tester à la main** :

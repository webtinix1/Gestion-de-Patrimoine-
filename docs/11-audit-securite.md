# 11 · Audit de sécurité

- **Date** : 29/10/2026.
- **Code audité** : branche `main`, commit 7913387.
- **Méthode** :
  - lecture du code dans quatre domaines : authentification et comptes, contrôle d'accès et périmètre, fichiers et injections, déploiement et configuration ;
  - contre-vérification dans le code des constats graves ;
  - `composer audit` et `npm audit`.
- **Ce qui n'a pas été fait** : aucun test d'intrusion sur l'application en marche. Les constats « à confirmer » n'ont pas été reproduits.

## Synthèse

- **Aucune faille critique.** Avec la matrice des droits livrée, un utilisateur de rôle faible ne peut ni s'élever en privilèges, ni lire ou modifier un bien hors de son périmètre.
- **Points faibles** : la délégation de la gestion des comptes, la tâche planifiée Windows, le réseau (HTTP en clair, pas d'en-têtes de sécurité) et une fuite du journal d'activité.

| Gravité | Nombre |
|---|---|
| Critique | 0 |
| Élevée | 2 (dont 1 conditionnelle) |
| Moyenne | 8 |
| Faible | 9 |

## Constats

### Élevée

**S1. La tâche planifiée tourne en SYSTEM sur un dossier modifiable par les utilisateurs** (vérifié)
- **Où** : `install.ps1:232-237`.
- **Ce qui se passe** : la tâche « Patrimoine - planificateur » lance `artisan schedule:run` chaque minute en SYSTEM, avec le niveau Highest. Aucune ACL n'est posée sur le dossier d'installation. Un dossier créé à la racine de `C:\` hérite en général de « Utilisateurs authentifiés : Modifier » (à confirmer sur le poste).
- **Scénario** : un utilisateur Windows non administrateur modifie `artisan`, `vendor/autoload.php` ou `.env`. Son code s'exécute en SYSTEM dans la minute qui suit, et il prend le contrôle du poste.
- **Correction** :
  - dans `install.ps1`, retirer l'héritage et ne laisser le contrôle total qu'à SYSTEM et Administrateurs ;
  - donner Modifier sur `storage` et `bootstrap\cache` seulement au compte d'Apache ;
  - sinon, faire tourner la tâche sous un compte de service sans privilèges.

**S2. Déléguer `users.update`, c'est donner le rôle Administrateur** (vérifié, conditionnel : par défaut, seul l'Administrateur a ce droit)
- **Où** :
  - `UserPolicy.php:30-46` : `update` et `resetPassword` ne regardent pas le rôle du compte cible ;
  - `UserForm.php` : le champ Rôle propose tous les rôles, Administrateur compris ;
  - `EditUser.php:51-62` et `SyncUserAccess.php:23` : rien n'interdit de changer son propre rôle ;
  - `RolePolicy.php` : la matrice des rôles se modifie avec le même droit `users.update`.
- **Scénario** : un rôle « Secrétaire » reçoit `users.update` pour créer des comptes. La personne peut alors :
  - s'attribuer le rôle Administrateur avec un périmètre « tous » ;
  - ou réinitialiser le mot de passe de l'administrateur, lire le mot de passe provisoire affiché, puis se connecter avec son compte ;
  - ou ajouter tous les droits à son propre rôle.
- **Correction** :
  - réserver aux administrateurs l'attribution du rôle Administrateur, ainsi que la modification ou la réinitialisation d'un compte administrateur ;
  - interdire de changer son propre rôle ou son propre périmètre, et d'accorder un périmètre plus large que le sien ;
  - créer un droit distinct pour la matrice des rôles, ou la réserver à l'Administrateur comme les Paramètres.

### Moyenne

**S3. Le journal d'activité montre des acquisitions hors périmètre** (vérifié)
- **Où** : `ActivityResource.php:98-120`. Le filtre de périmètre ne couvre que les biens, les prêts et les inventaires. Les entrées portant sur une acquisition passent sans filtre.
- **Scénario** : un utilisateur limité au site A, qui a le droit de consulter le journal, voit les validations d'acquisitions des autres sites, avec les numéros des biens créés.
- **Correction** :
  - filtrer les acquisitions par leur scope de périmètre ;
  - mieux, réserver les types d'entrées non reconnus aux utilisateurs sans périmètre restreint.

**S4. Aucune protection du dernier administrateur** (vérifié)
- **Ce qui se passe** : l'unique administrateur peut se retirer lui-même le rôle Administrateur. Plus personne ne peut alors gérer les comptes, et il faut intervenir en base pour réparer.
- **Correction** : dans `SyncUserAccess` et `SetUserActive`, refuser toute opération qui laisse zéro administrateur actif.

**S5. Blocage volontaire d'un compte, et révélation de son existence** (vérifié)
- **Où** :
  - `Login.php:20-31` : un compte bloqué affiche un message spécifique avec l'heure de fin du blocage ;
  - `RecordFailedLogin.php` : le blocage se fait par compte, sans tenir compte de l'adresse IP.
- **Scénario** : n'importe quel poste du réseau tape 5 mots de passe faux sur `admin` et bloque l'administrateur 15 minutes, en boucle. Le message permet aussi de savoir si un identifiant existe.
- **Correction** :
  - afficher un message générique ;
  - compter les échecs par couple (identifiant, IP) avec `RateLimiter` ;
  - incrémenter le compteur de façon atomique.

**S6. Pas d'en-têtes de sécurité HTTP sur les pages** (vérifié)
- **Ce qui manque** : aucun `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` ni CSP, sauf sur les trois contrôleurs de fichiers.
- **Scénario** : clickjacking, c'est-à-dire l'application affichée dans une page piégée.
- **Correction** : un middleware web, ou des en-têtes `Header always set` dans le vhost Apache. Au minimum `frame-ancestors 'self'`.

**S7. HTTP en clair sur le réseau local** (vérifié)
- **Ce qui se passe** : l'adresse par défaut est `http://patrimoine.local`, et le cookie de session n'est pas marqué Secure. Les mots de passe et le cookie de session passent en clair. Un poste compromis ou un Wi-Fi mal protégé permet de les capter.
- **Correction** :
  - HTTPS avec un certificat interne ;
  - `SESSION_SECURE_COOKIE=true` ;
  - à défaut, documenter le risque accepté.

**S8. Base MySQL accédée en root, mot de passe vide accepté** (vérifié)
- **Où** : `install.ps1:36` et `:174-178`.
- **Correction** :
  - créer un utilisateur MySQL dédié, limité à la base `patrimoine` ;
  - refuser un mot de passe vide hors mode `-Demo` ;
  - vérifier que MySQL n'écoute que sur 127.0.0.1.

**S9. Pare-feu non scripté et non borné** (vérifié)
- **Ce qui se passe** : la doc 07 ouvre le port 80 à tout le réseau.
- **Correction** : `New-NetFirewallRule` dans `install.ps1`, limité aux adresses des 3 postes ou au sous-réseau.

**S10. La désactivation d'un compte ne supprime pas ses sessions** (vérifié pour l'absence de suppression ; l'impact reste à confirmer)
- **Ce qui protège déjà** : `canAccessPanel()` et les policies testent `is_active`, ce qui refuse très probablement l'accès dès la requête suivante.
- **Correction** : supprimer les sessions de l'utilisateur dans `SetUserActive`.

### Faible

**S11.** Une sortie de bien peut régulariser une ligne d'inventaire sans le droit de régularisation.
- **Où** : `RecordDisposal.php:355-380`, via le paramètre `?inventaire_ligne=`.
- **Correction** : appeler `Gate::authorize('regularize', $inventory)` avant la régularisation.

**S12.** Les listes de responsables ne sont pas filtrées par périmètre.
- **Où** : `TransferAction`, `AssetReportActions`, `AssetForm` ; `TransferAssets` accepte n'importe quel `holder_id`.
- **Correction** : appliquer `->inPerimeter()` partout, y compris dans la validation serveur.

**S13.** Le champ photo d'un bien accepte un chemin déjà stocké.
- **Où** : `AssetForm.php:64-71`.
- **Risque** : si l'état Livewire est forgé, la photo pourrait pointer vers un document privé (à confirmer).
- **Correction** : n'accepter que les chemins `biens/photos/` et restreindre les types à jpg, png et webp.

**S14.** Le compte de démonstration a un mot de passe public, défini dans `DemoSeeder::ADMIN_PASSWORD`.
- **Problème** : `--force` lève le garde-fou de production, et le seeder ne vérifie pas l'environnement.
- **Correction** : ajouter `abort_if(app()->isProduction())` dans `DemoSeeder::run()`.

**S15.** Le premier administrateur n'est pas obligé de changer son mot de passe.
- **Où** : `InstallCommand.php:249`.
- **Correction** : l'obliger quand le mot de passe est passé par stdin.

**S16.** La politique de mot de passe est minimale : 8 caractères dont un chiffre. Le nouveau mot de passe peut être identique au provisoire.

**S17.** Les échecs de connexion simples et les déconnexions ne sont pas journalisés. Seul le blocage du compte l'est.

**S18.** Le journal d'activité n'est protégé que par Eloquent. `activitylog:clean` ou un accès SQL direct peuvent l'effacer.
- **Correction** : des triggers MySQL `BEFORE UPDATE/DELETE` si une vraie immuabilité est exigée.

**S19.** La restauration exécute le dump SQL d'une sauvegarde sans signature : le SHA-256 est stocké dans la sauvegarde elle-même.
- **Risque** : il faut un accès au disque et à la CLI pour en profiter.
- **Correction** : signer le manifeste avec un HMAC, ou documenter que les sauvegardes sont de confiance.

### Dépendances

- **`composer audit`** : 1 faille d'importance moyenne, **CVE-2026-45070** dans `symfony/mime` (injection d'en-tête dans les e-mails). L'impact est faible, car l'application n'envoie pas d'e-mail. Correction : `composer update symfony/mime`.
- **`npm audit`** : 0 faille.

### Information

- `EnsurePasswordIsChanged` laisse passer toutes les requêtes Livewire. L'impact est faible, car l'utilisateur garde ses droits normaux.
- `.env.example` a des valeurs de développement (`APP_DEBUG=true`). `install.ps1` les corrige pour un `.env` neuf.
- Les exports d'amortissement et de biens n'ont pas de `Gate` dans l'Action. Ce n'est pas exploitable, car Filament 5 refuse une action masquée, mais un contrôle dans l'Action servirait de défense en profondeur.
- Une recherche complète de secrets dans l'historique git n'a pas abouti (délai dépassé). Les recherches ciblées n'ont rien trouvé.

## Vérifié et correct

**Fichiers**
- Les documents et photos sont stockés sur le disque privé et servis par des contrôleurs authentifiés.
- Ces contrôleurs appliquent le périmètre et la policy, et envoient `nosniff`, une CSP `sandbox` et `no-store`.
- Le nom stocké est aléatoire, et aucun chemin ne vient d'une saisie.

**Injections**
- Aucune injection SQL : toutes les requêtes brutes sont constantes ou utilisent des paramètres liés.
- Pas de XSS : le journal passe par `e()`, et le Markdown des guides est filtré.
- Pas d'injection de gabarit (SSTI) dans les étiquettes.
- dompdf tourne sans accès distant ni PHP.
- Les exports Excel forcent le type texte, donc pas d'injection de formules.
- Aucun `unserialize` ; les modèles utilisent `$fillable`.

**Sauvegarde et restauration**
- `Process` est appelé avec un tableau d'arguments, sans shell. Le mot de passe passe par un fichier temporaire en 0600.
- Les chemins relatifs du manifeste sont validés, ce qui empêche de sortir du dossier de restauration.
- La destination ne peut pas être dans le dossier web, et `.env` est exclu.
- Les deux opérations sont réservées à la CLI.

**Contrôle d'accès**
- Toutes les Actions métier revérifient le droit et relisent les biens avec le scope de périmètre, sous verrou.
- Les paramètres d'URL forgés (`?bien=`, `?biens=`, `?signalement=`) sont refiltrés.
- La recherche par code répond « inexistant » pour un bien hors périmètre.

**Authentification**
- Pas de « rester connecté ». `AuthenticateSession` et CSRF sont actifs.
- Les mots de passe sont hachés et absents du journal.
- Les mots de passe provisoires sont aléatoires et doivent être changés à la première connexion.
- Les Paramètres sont réservés en dur à l'Administrateur.
- Les comptes et les biens ne peuvent pas être supprimés. Le rôle Administrateur ne peut pas être modifié.

**Déploiement**
- `.env` et la base SQLite sont ignorés par git.
- Le dossier web est `public/`, sans listing de répertoires.
- `install.ps1` génère `APP_KEY`, désactive le débogage et lit le mot de passe administrateur sans l'afficher.
- Les pages d'erreur ne montrent pas de trace en production.

## Ordre de correction recommandé

1. **S1 (tâche SYSTEM)** et **S2 (délégation des comptes)** : ce sont les seuls vrais chemins d'élévation de privilèges.
2. **Code** : S3, S4, S5, S6, S10, S11, S12, et la mise à jour de `symfony/mime`.
3. **Installation** : S7, S8, S9, en lien avec l'Église (HTTPS, compte MySQL dédié, pare-feu).
4. **Le reste** (S13 à S19), selon le temps disponible.

## État des corrections (lot 13, 30/10/2026)

Tous les constats S1 à S19 sont traités, ainsi que la mise à jour de `symfony/mime` ; le détail est dans `docs/08-journal-technique.md`, lot 13. Trois points restent des risques acceptés, à confirmer avec l'Église :

- **S5** : le blocage reste par compte (RG-85). Le message est désormais générique et chaque échec est tracé avec l'IP (QL13-1).
- **S7** : HTTPS est documenté dans docs/07 mais pas imposé (QL13-2).
- **Information** : les requêtes Livewire ne sont pas filtrées tant que le mot de passe provisoire n'est pas changé (QL13-4).

Les mesures propres à l'installation Windows (droits NTFS, pare-feu, compte MySQL) n'ont été vérifiées que par l'analyse syntaxique de `install.ps1`. Elles restent à tester sur le poste de l'Église.

# Écrans et construction Filament

Maquette de référence (Claude Design, 45 écrans numérotés) : https://claude.ai/artifact/XRAL12FZTYccMmwrdut4wM
Elle doit être partagée avec l'équipe depuis le menu Partager pour être ouverte. Les numéros ci-dessous (E01 à E45) sont ceux des cadres de la maquette.

Légende : **R** ressource Filament (liste, création, édition, consultation), **P** page sur mesure, **A** action (fenêtre ou étapes), **W** widget, **RM** gestionnaire de relation (onglet sur une fiche).

## Navigation

| Groupe | Entrées (dans cet ordre) |
|---|---|
| Accueil | Tableau de bord · Alertes |
| Patrimoine | Biens · Recherche par code · Étiquettes · Acquisitions |
| Opérations | Mouvements · Prêts et emprunts · Maintenance · Signalements · Inventaires · Sorties et réformes |
| Immobilisations | Tableau des immobilisations · Règles d'amortissement · Exercices comptables |
| Pilotage | Rapports · Documents |
| Structure | Sites · Départements et services · Locaux · Catégories et référentiels |
| Administration | Utilisateurs · Rôles et permissions · Journal des opérations · Paramètres |

Le menu se réduit selon les permissions : un responsable de département voit seulement son espace, ses biens, la recherche par code, ses signalements, ses inventaires et les rapports autorisés (E42). Barre du haut : recherche globale (numéro, désignation, n° de série), bouton Scanner, cloche des alertes, menu utilisateur.

## Correspondance des écrans

| Maquette | Écran | Construction |
|---|---|---|
| E01 | Connexion | Page de connexion Filament personnalisée (identifiant au lieu de l'e-mail, blocage RG-85) |
| E02 | Tableau de bord | P Dashboard : bandeau titre, W valeurs (acquisition, VNC, dotations), W compteurs par statut, W barres par département, W répartition par statut et par site, W acquisitions récentes, W transferts récents, W biens à traiter, W dernier inventaire |
| E03 | Alertes | P liste des notifications base de données, filtres par type, « tout marquer comme lu » |
| E04 | Mon profil | Page profil Filament : infos, changement de mot de passe, périmètre en lecture, dernières opérations |
| E05 | Liste des biens | R Asset, table : filtres (recherche, catégorie, site, département, service, statut, état, local, responsable, période d'acquisition), actions groupées (imprimer étiquettes, transférer, ajouter à un inventaire), exports Excel et PDF |
| E06 | Fiche d'un bien | R Asset vue : en-tête (photo, numéro, badges, aperçu étiquette), compteurs (VO, cumul, VNC, durée restante), onglets Informations (infolist), Affectation et mouvements (RM), Amortissement (RM lignes + lien E31), Maintenance (RM), Prêts (RM), Documents (RM), Journal. Actions d'en-tête : Modifier, Imprimer l'étiquette, Transférer, Prêter, Maintenance, Sortir |
| E07 | Créer / modifier un bien | R Asset formulaire en sections, bloc Immobilisation conditionnel, numéro affiché « attribué à l'enregistrement », bouton « Enregistrer et imprimer l'étiquette » |
| E08 | Recherche par code | P champ autofocus (Entrée = recherche), résultat en carte avec actions rapides, historique des codes lus sur le poste |
| E09 | Impression des étiquettes | P sélection des biens, modèle, support (planche ou rouleau), case de départ, aperçu, génération PDF |
| E10 | Modèle d'étiquette | R LabelTemplate avec aperçu en direct |
| E11, E12 | Acquisitions | R Acquisition, formulaire avec Repeater des lignes, A « Valider et créer les fiches » (RG-15), puis redirection vers E09 |
| E13 | Mouvements | R Movement en lecture seule (liste filtrable) |
| E14 | Transfert | A Transférer (unitaire et groupée) : affectation actuelle en lecture, nouvelle affectation, champs modifiés mis en évidence, date, motif, justificatif, case « réimprimer l'étiquette » |
| E15, E16, E17 | Prêts, nouveau prêt, retour | R Loan avec onglets (en cours, en retard, emprunts, historique), formulaire prêt ou emprunt, A Retour (état par bien, alerte si dégradé, proposition d'intervention) |
| E18 à E21 | Inventaires | R Inventory, A Démarrer (fige la liste), P Saisie (scan, compteurs en direct, boutons Présent, Manquant, Autre affectation, état constaté, biens non enregistrés), P Rapport (sections par écart, régularisations, A Valider et clôturer, PV PDF) |
| E22, E23 | Maintenance | R MaintenanceJob, A Clôturer l'intervention |
| E24, E25 | Signalements | R AssetReport : formulaire simplifié pour le responsable, A Traiter (crée l'opération liée) et A Rejeter pour le gestionnaire |
| E26, E27 | Sorties | R Disposal, formulaire selon le motif, panneau des valeurs à la date de sortie, A Confirmer (saisie du numéro), A Valider (comptable) |
| E28 | Documents | R Document : filtres par type, ajout multiple avec rattachement, aperçu |
| E29 | Rapports | P catalogue (inventaire général, par département, site, catégorie, local, acquisitions, mouvements, prêts, maintenance, immobilisations, dotations, cumuls, VNC, totalement amorties, sorties, écarts d'inventaire), filtres communs, aperçu, export PDF et Excel |
| E30 | Tableau des immobilisations | P : sélecteur d'exercice, compteurs, onglets (en cours, échéance atteinte, totalement amorties, non amortissables), regroupement par catégorie, site ou département, récapitulatif par catégorie |
| E31 | Plan d'amortissement | P par bien : paramètres, tableau par exercice avec statut, graphique VNC, historique des modifications, A Modifier les paramètres (motif obligatoire, RG-37), export PDF (E45) et Excel |
| E32 | Règles d'amortissement | P paramètres généraux + table éditable des règles par catégorie |
| E33 | Exercices comptables | R FiscalYear, A Clôturer avec liste des points d'attention (RG-42) |
| E34 à E37 | Sites, départements et services, locaux, catégories et référentiels | R simples ; départements en arbre avec services ; catégories, états, statuts (lecture), motifs et financements en onglets |
| E38 | Utilisateurs | R User : rôle, périmètre, mot de passe provisoire, A Réinitialiser, A Désactiver |
| E39 | Rôles et permissions | Filament Shield, matrice module × droit, libellés français |
| E40 | Journal des opérations | R Activity en lecture seule, filtres, affichage avant / après |
| E41 | Paramètres | P paramètres : identité, format du numéro, alertes, sécurité, état du serveur |
| E42 | Espace du responsable | P tableau de bord filtré sur le périmètre du responsable |
| E43 | Fiche d'un bien sorti | R Asset vue en lecture seule quand `disposed_at` est renseigné, bandeau « Sorti du patrimoine » |
| E44 | États système | Pages d'erreur 403, 404, 419 (session expirée), notifications Filament, contrôle de modification simultanée (RG-86), confirmation d'action irréversible, état vide, serveur injoignable |
| E45 | Documents imprimés | Gabarits PDF (DomPDF) : plan d'amortissement, PV d'inventaire, fiche de prêt, étiquettes |

## Détails de style

- Couleurs : primaire `#0B5D6B`, accent `#F2B544` (bouton d'action principale sur fond foncé, repère du menu actif), encre `#2E2E2E`, gris `#686868`, filets `#D7DBE1`, fond `#F4F6F8`, menu latéral `#262626`.
- Couleurs de statut (fond / texte) : succès `#E4F2EA` / `#1E6E43`, avertissement `#FBF0D9` / `#8A5A00`, danger `#FBE7E5` / `#A8261B`, info `#E6EBF8` / `#2F4A9E`, neutre `#E9ECF0` / `#4A5560`.
- Typo : Archivo (textes), Martian Mono (numéros patrimoniaux, montants, codes). Titres de page en gras 800, titres de section en capitales espacées.
- Badges : capitales, 11 px, angles droits. Cartes : rayon 12 px, filet 1 px, pas d'ombre.
- Contrastes AA minimum, zones cliquables de 44 px.

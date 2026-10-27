# Questions ouvertes

Questions à faire trancher par l'Église avant ou pendant le développement. Pour chacune : le lot qu'elle bloque, et la valeur appliquée par défaut tant qu'il n'y a pas de réponse (toujours paramétrable). Claude Code ajoute ici toute nouvelle question rencontrée.

## À valider avec le comptable (bloquant pour le lot 7)

| N° | Question | Par défaut |
|---|---|---|
| Q1 | Mode de prorata de la première et de la dernière année : jours sur 360, jours réels sur 365, mois entiers ? | Jours sur 360 (RG-32) |
| Q2 | Seuil d'immobilisation : en dessous, le bien est suivi sans amortissement. Quel montant ? | 100 000 FCFA |
| Q3 | Durées et comptes SYSCOHADA par catégorie (tableau de `docs/02` et écran E32) : à confirmer ligne par ligne. | Valeurs de la maquette |
| Q4 | La méthode dégressive est-elle utilisée ? Si oui, quels coefficients ? | Désactivée |
| Q5 | L'exercice comptable est-il l'année civile ? | 01/01 au 31/12 |
| Q6 | Utilise-t-on une valeur résiduelle ? | Nulle |
| Q7 | Comment traiter un changement de durée en cours de vie : répartition de la VNC sur la durée restante (cas A6) ? | Oui |
| Q8 | Faut-il afficher une plus ou moins-value indicative à la sortie, ou seulement la VNC ? | VNC seule, plus-value indicative pour les cessions |
| Q9 | Validation des 8 cas de `tests/Fixtures/amortissements.json` : signature du comptable sur une copie imprimée. | Non validés |

## À valider avec l'administration de l'Église

| N° | Question | Bloque | Par défaut |
|---|---|---|---|
| Q10 | Format du numéro patrimonial : `PAT-000001`, avec l'année, ou par catégorie ? Quel préfixe ? | Lot 3 | `PAT-000001` |
| Q11 | Le responsable d'un bien est-il toujours un utilisateur de l'application, ou parfois une simple personne (fidèle, bénévole) ? | Lot 3 | Personne simple, liée ou non à un compte |
| Q12 | Biens en grand nombre (chaises, bancs) : une fiche par unité ou gestion par lot ? | Lot 3 | Une fiche par unité ; lot hors V1 |
| Q13 | Reprise de l'existant : combien de biens, sous quelle forme (cahier, Excel) ? Qui saisit ? Que faire des biens sans facture ni valeur connue ? | Lot 12 | Import Excel fourni par Webtinix, saisie par l'Église, valeur estimée marquée comme telle |
| Q14 | Liste réelle des sites, départements, services et locaux. | Lot 1 | Données de démonstration |
| Q15 | Nom, sigle et logo de l'Église pour les étiquettes et les rapports. | Lot 4 | `[Nom de l'Église]` |
| Q16 | Contenu de l'étiquette et matériel : imprimante d'étiquettes ou planches A4 (quelle référence de planche) ? Douchettes 2D (indispensables pour le QR code) ? Qui les fournit ? | Lot 4 | Planche A4 3 × 8 et QR code |
| Q17 | Les autres sites (paroisses, annexes) ont-ils accès au réseau du serveur ? Sinon, ils ne pourront pas utiliser l'application en V1. | Lot 12 | Accès uniquement depuis le réseau du site serveur |
| Q18 | Volumétrie : nombre de biens et d'utilisateurs simultanés. | Lot 12 | 5 000 biens, 10 utilisateurs simultanés |
| Q19 | Serveur : PC dédié ou serveur ? Windows ou Linux ? Onduleur ? | Lot 12 | PC dédié sous Linux (Ubuntu LTS) ou Windows avec Laragon |
| Q20 | Sauvegarde : la sauvegarde automatisée est hors V1. Acceptez-vous une commande de sauvegarde manuelle (base + fichiers vers un disque externe), proposée en option ? | Lot 12 | Proposée en option |
| Q21 | Qui valide les sorties du patrimoine (conseil, pasteur, administrateur) ? | Lot 10 | Texte libre |
| Q22 | Motifs de transfert : la maquette (E37) montre une liste (réaffectation entre départements, changement de responsable, déménagement, régularisation d'inventaire), le modèle de données prévoit un texte libre (`movements.reason`). Liste fermée, liste paramétrable ou texte libre ? *(ajoutée au lot 1)* | Lot 6 | Texte libre avec ces quatre suggestions |
| Q23 | Comptes SYSCOHADA par catégorie : la maquette ne donne que ceux du matériel informatique (2441 / 28441). Compléter les comptes d'immobilisation, d'amortissement et de dotation des neuf autres catégories (précise Q3). *(ajoutée au lot 1)* | Lot 7 | Vides, saisissables dans Catégories et référentiels |
| Q60 | Un utilisateur dont le périmètre est limité (un département, un site) peut-il transférer un bien vers une affectation hors de son périmètre ? Après le transfert, il ne voit plus le bien. *(ajoutée au lot 6)* | Lot 6 | Oui, le transfert est accepté ; seul le bien de départ doit être dans le périmètre |
| Q61 | Modifier le site, le département, le service, le local ou le responsable depuis le formulaire de modification d'un bien : doit-on le réserver à l'action Transférer ? *(ajoutée au lot 6)* | Lot 6 | Autorisé, mais un mouvement « Modification de la fiche » est enregistré pour respecter RG-21 |
| Q62 | Réimpression des étiquettes après un transfert (RG-24) : le lien vers la page des étiquettes porte les identifiants des biens (`/admin/etiquettes?biens=12,15`). Le modèle d'étiquette fait-il toujours figurer le site et le département ? *(ajoutée au lot 6)* | Lot 4 | Réimpression proposée dès que le site ou le département change |

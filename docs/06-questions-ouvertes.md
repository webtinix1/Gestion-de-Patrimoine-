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
| Q9.1 | Bien enregistré avec une mise en service antérieure à des exercices déjà clôturés (reprise de l'existant, saisie tardive) : faut-il des lignes théoriques pour ces exercices passés, ou un rattrapage du cumul sur l'exercice ouvert ? *(ajoutée au lot 7)* | Lignes théoriques depuis la mise en service, figées aussitôt (statut « Antérieur » ou « Clôturé »), sans rattrapage |
| Q9.2 | Correction d'une erreur de saisie (prix, date de mise en service) : même traitement qu'un changement de paramètre ? *(ajoutée au lot 7)* | Depuis la fiche : recalcul complet sans motif tant que le plan n'est ni validé ni figé ; ensuite refusé, à faire depuis le plan avec motif (RG-37, prospectif) |
| Q9.3 | Changement de paramètre impossible sans toucher un exercice clôturé (nouvelle base inférieure au cumul figé, nouvelle durée terminée avant l'exercice ouvert) : refus, ou dotation exceptionnelle ? *(ajoutée au lot 7)* | Refusé avec un message |
| Q9.4 | Dégressif (si retenu, Q4) : base = VNC moins valeur résiduelle ? Durée de moins de 3 ans ? Sortie en cours d'exercice comptée en mois jusqu'au mois de sortie inclus ? *(ajoutée au lot 7)* | Base = VNC − valeur résiduelle ; refusé sous 3 ans ; mois de sortie inclus ; bascule si l'annuité linéaire est strictement supérieure |
| Q9.5 | Bien amortissable sans date de mise en service (par exemple en stock) : départ à la date d'acquisition ou pas de plan ? *(ajoutée au lot 7)* | Pas de plan tant que la date manque (signalé à la clôture) |
| Q9.6 | Clôture : les plans de l'exercice sont « validés » par la clôture (RG-38) au nom de la personne qui clôture ; les exercices se clôturent dans l'ordre chronologique. À confirmer. *(ajoutée au lot 7)* | Oui, les deux |
| Q9.7 | Exercice non civil (Q5) : le calcul suppose l'année civile ; un exercice décalé demanderait d'adapter le prorata. *(ajoutée au lot 7)* | Année civile imposée à la création d'un exercice |
| Q9.8 | Février et le 31 avec la formule RG-32 (mois de 30 jours) : mise en service le 28/02 → 303 jours la première année, le 29/02 → 302, le 01/03 → 300 ; le 30/12 et le 31/12 → 1 jour chacun. Ces résultats conviennent-ils ? *(revue du lot 7)* | Formule RG-32 appliquée telle quelle |
| Q9.9 | Bien saisi après la clôture d'exercices où il aurait dû être amorti (précise Q9.1) : les lignes théoriques de ces exercices clôturés n'ont jamais été comptabilisées. Les garder telles quelles, ou reporter le cumul en rattrapage sur l'exercice ouvert ? *(revue du lot 7)* | Lignes théoriques figées, sans rattrapage |
| Q9.10 | Date de mise en service corrigée après la clôture d'un exercice qui porte déjà une dotation : refuser (seul un déplacement à l'intérieur du même exercice est accepté), ou corriger l'écart sur l'exercice ouvert ? *(revue du lot 7)* | Refus si la date sort de l'exercice figé ; dans le même exercice, la dotation figée reste et le reste est réparti |
| Q9.11 | Taux affiché après un recalcul RG-37 ou après la bascule du dégressif : la dotation découle de la VNC restante, pas d'un taux annuel (il dépasserait parfois 100 %). Afficher « — » sur ces lignes et le taux du plan en tête, ou un taux équivalent ? *(revue du lot 7)* | « — » sur la ligne, taux du plan dans les paramètres |
| Q9.12 | Sortie saisie après la clôture de l'année de sortie (lot 10) : la dotation figée de cet exercice ne peut plus être arrêtée à la date de sortie. Refuser la sortie antidatée, ou régulariser sur l'exercice ouvert ? *(revue du lot 7)* | À trancher avant le lot 10 ; par prudence, refus d'une sortie datée dans un exercice clôturé |

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
| Q5.1 | Acquisitions : la matrice (E39) ne donne pas le droit « valider » au gestionnaire du patrimoine, seul l'administrateur peut donc valider une acquisition et créer les fiches. Le gestionnaire doit-il pouvoir valider ? *(ajoutée au lot 5)* | Lot 5 | Matrice appliquée telle quelle |
| Q5.2 | Fiches créées par une acquisition : quels état et statut initiaux (la maquette ne le dit pas) ? Le local, le responsable, le numéro de série et la date de mise en service restent à compléter sur chaque fiche. *(ajoutée au lot 5)* | Lot 5 | État « Neuf », statut « En service », mise en service vide |
| Q5.3 | Acquisitions : qui supprime un brouillon (la matrice réserve « supprimer » à l'administrateur) ? La référence ACQ-AAAA-NNN prend l'année de la date d'acquisition à la création, n'est jamais réutilisée, et l'année d'un brouillon ne peut plus changer ensuite. *(ajoutée au lot 5)* | Lot 5 | Suppression d'un brouillon autorisée à qui peut le modifier ; année de la date figée |
| Q5.4 | Acquisitions : la maquette affiche une colonne « Étiquettes » (à imprimer / imprimées) et un compteur « Étiquettes à imprimer ». Elle suppose un suivi des impressions par bien (lot 4). *(ajoutée au lot 5)* | Lot 4 | Colonne non affichée tant que le suivi des impressions n'existe pas |
| Q60 | Un utilisateur dont le périmètre est limité (un département, un site) peut-il transférer un bien vers un site ou un département hors de son périmètre ? Il ne verrait plus le bien ensuite. *(ajoutée au lot 6)* | Lot 6 | Non : décision prudente appliquée (le transfert est refusé avec un message), à confirmer par l'Église |
| Q61 | Modifier le site, le département, le service, le local ou le responsable depuis le formulaire de modification d'un bien : doit-on le réserver à l'action Transférer ? *(ajoutée au lot 6)* | Lot 6 | Autorisé, mais le mouvement est enregistré (motif « Modification de la fiche ») et la date d'affectation passe à aujourd'hui |
| Q62 | Réimpression des étiquettes après un transfert (RG-24) : le lien vers la page des étiquettes porte les identifiants des biens (`/admin/etiquettes?biens=12,15`). Le modèle d'étiquette fait-il toujours figurer le site et le département ? *(ajoutée au lot 6)* | Lot 4 | Réimpression proposée dès que le site ou le département change |
| Q63 | Transfert groupé : faut-il écarter automatiquement un bien déjà à la destination plutôt que de refuser tout le lot ? *(ajoutée au lot 6)* | Lot 6 | Tout le lot est refusé, avec la liste des biens concernés |
| Q64 | Transfert groupé : faut-il une option « inchangé » par élément (garder le local ou le responsable actuel de chaque bien) ? *(ajoutée au lot 6)* | Lot 6 | Non : un élément laissé vide est vidé pour tous les biens |

## Sorties du patrimoine (lot 10)

| N° | Question | Qui | Par défaut |
|---|---|---|---|
| QL10-1 | Sortie d'un bien emprunté ou en maintenance (vol pendant un prêt, bien déclaré irréparable) : faut-il pouvoir le sortir directement ? *(ajoutée au lot 10)* | Administration | Refusé : on enregistre d'abord le retour du prêt ou la clôture de l'intervention, puis la sortie (le lot 8 proposera la sortie après une intervention « irréparable ») |
| QL10-2 | Bien sans plan d'amortissement (non amortissable sous le seuil, ou sans date de mise en service) : quelles valeurs figer à la sortie ? *(ajoutée au lot 10)* | Comptable | Valeur d'origine = prix d'acquisition, cumul 0, VNC = valeur d'origine |
| QL10-3 | Q9.12 appliquée : une sortie datée dans un exercice clôturé, ou dans une année antérieure au premier exercice recalculable, est refusée (RG-36). Faut-il au contraire une régularisation sur l'exercice ouvert ? *(ajoutée au lot 10)* | Comptable | Refus, avec un message qui renvoie au comptable |
| QL10-4 | Aucune annulation dans l'application (RG-53) : en cas d'erreur de saisie (mauvais bien, mauvaise date), quelle procédure ? *(ajoutée au lot 10)* | Administration | Aucune dans l'application ; correction technique hors application, à tracer par écrit |
| QL10-5 | La validation comptable d'une sortie (RG-54) est définitive et peut intervenir après la clôture de l'exercice ; la clôture ne valide pas les sorties (elle les signale, RG-42). À confirmer. *(ajoutée au lot 10)* | Comptable | Oui |
| QL10-6 | Vol et perte : la maquette affiche une date du constat et une référence de déclaration, absentes du modèle de données. Champs dédiés, ou saisie dans « Référence de la décision » et « Observations » ? *(ajoutée au lot 10)* | Administration | Saisie dans ces deux champs |
| QL10-7 | Justificatif de sortie obligatoire (PV, déclaration) ? *(ajoutée au lot 10)* | Administration | Facultatif |
| QL10-8 | Plan validé avant la sortie : la sortie crée une nouvelle version arrêtée, non validée, que la clôture validera (RG-38). Le comptable doit-il la valider avant ? *(ajoutée au lot 10)* | Comptable | Non : validée par la clôture, ou à la main depuis le plan |

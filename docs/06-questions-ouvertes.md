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

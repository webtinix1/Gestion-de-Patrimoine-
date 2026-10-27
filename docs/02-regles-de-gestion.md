# Règles de gestion

Ce document traduit le cahier des charges en règles vérifiables. Chaque règle porte un numéro (RG-xx) que le code et les tests citent. Les règles marquées **[À VALIDER]** dépendent d'une réponse du client : voir `06-questions-ouvertes.md`. Tant qu'elles ne sont pas validées, on applique la valeur indiquée par défaut, rendue paramétrable.

## Identification des biens

- **RG-01.** Chaque bien reçoit un numéro patrimonial à sa création, généré par l'application, jamais saisi à la main.
- **RG-02.** Format par défaut `PAT-000001` (préfixe + 6 chiffres). Deux autres formats sont paramétrables : avec l'année (`PAT-2026-000001`, compteur remis à zéro chaque année) et par catégorie (`INF-000001`, un compteur par préfixe de catégorie). Le format choisi ne s'applique qu'aux nouveaux biens.
- **RG-03.** Le numéro est unique, même si deux utilisateurs créent un bien au même instant : génération dans une transaction avec verrou sur le compteur, plus un index unique en base.
- **RG-04.** Le numéro n'est jamais modifié (transfert, changement de catégorie, sortie) et jamais réattribué, même après une sortie du patrimoine.
- **RG-05.** Une recherche par numéro, saisi à la main ou lu par une douchette USB (qui se comporte comme un clavier suivi de Entrée), ouvre directement la fiche du bien. Un numéro inconnu affiche « Aucun bien avec ce code » et propose de créer la fiche.

## Biens

- **RG-10.** Champs obligatoires à la création : désignation, catégorie, date d'acquisition, site, département, état, statut. Le prix d'acquisition est obligatoire si le bien est amortissable.
- **RG-11.** L'état (condition physique : Neuf, Très bon état, Bon état, État moyen, À réparer, Hors service) et le statut (situation administrative : En service, En stock, En maintenance, Emprunté, Hors service, Cédé, Réformé) sont deux champs distincts. Les listes d'états sont paramétrables ; les statuts sont fixes (Enum).
- **RG-12.** Les statuts En maintenance, Emprunté, Cédé et Réformé ne se choisissent pas à la main : ils sont posés par une intervention, un prêt ou une sortie, et levés par la clôture de l'opération.
- **RG-13.** Le numéro de série, s'il est renseigné, est unique parmi les biens actifs. En cas de doublon, l'enregistrement est refusé avec un lien vers le bien existant.
- **RG-14.** Un bien peut avoir une photo (facultative) et plusieurs documents.
- **RG-15.** Une acquisition regroupe les lignes d'une même facture. Pour une ligne marquée « suivi individuel », la validation crée une fiche et un numéro par unité (quantité 3 = 3 fiches). Une ligne « non suivie » (consommable) ne crée pas de fiche.
- **RG-16.** Après validation d'une acquisition, l'application propose d'imprimer les étiquettes des biens créés.
- **RG-17. [À VALIDER]** Gestion par lot (ex. 300 chaises identiques) : hors V1 par défaut, chaque bien a sa fiche. Si le client la demande, elle fera l'objet d'un avenant.

## Affectations et mouvements

- **RG-20.** Un bien a toujours une affectation courante : site, département, service (facultatif), local (facultatif), responsable (facultatif).
- **RG-21.** Tout changement d'au moins un de ces cinq éléments crée un mouvement qui conserve l'affectation précédente et la nouvelle, la date, le motif, l'utilisateur et les observations. L'affectation précédente n'est jamais écrasée.
- **RG-22.** L'affectation initiale (à la création) est enregistrée comme premier mouvement de type « Affectation initiale ».
- **RG-23.** Un transfert peut porter sur plusieurs biens à la fois ; il crée un mouvement par bien.
- **RG-24.** Après un transfert qui change une information imprimée sur l'étiquette (département ou site selon le modèle), l'application propose de réimprimer l'étiquette.

## Immobilisations et amortissements

- **RG-30.** Un bien est amortissable si sa catégorie l'est et si son prix d'acquisition atteint le seuil d'immobilisation. **[À VALIDER]** Seuil par défaut : 100 000 FCFA, paramétrable. Le gestionnaire peut forcer « non amortissable » sur une fiche.
- **RG-31.** Paramètres par défaut issus de la catégorie (méthode, durée, comptes), modifiables sur la fiche. Le taux linéaire est calculé : 100 / durée.
- **RG-32. [À VALIDER]** Méthode linéaire. Base amortissable = valeur d'origine moins valeur résiduelle (0 par défaut). Prorata temporis en jours sur une année de 360 jours, mois de 30 jours, à partir de la date de mise en service incluse. Jours de la première année = (12 − mois) × 30 + (30 − min(jour, 30) + 1). Dotation d'un exercice = base × jours / 360 / durée.
- **RG-33.** Chaque dotation est arrondie au franc le plus proche (demi-unité vers le haut). La dernière dotation absorbe l'écart d'arrondi pour que le cumul égale exactement la base amortissable.
- **RG-34.** Le cumul des amortissements ne dépasse jamais la base amortissable ; la VNC n'est jamais négative et ne descend pas sous la valeur résiduelle.
- **RG-35. [À VALIDER]** Méthode dégressive : désactivée par défaut. Si activée, taux = taux linéaire × coefficient (1,5 pour 3 à 4 ans, 2 pour 5 à 6 ans, 2,5 au-delà), prorata en mois à partir du mois de mise en service, bascule en linéaire dès que l'annuité linéaire sur la durée restante est supérieure.
- **RG-36.** Les exercices comptables (année civile par défaut) sont ouverts ou clôturés. Les lignes de plan d'un exercice clôturé sont figées : aucun recalcul ne les modifie.
- **RG-37.** Une modification d'un paramètre d'amortissement (durée, méthode, valeur résiduelle, date de mise en service, valeur d'origine) exige un motif, est historisée (ancienne valeur, nouvelle valeur, date, utilisateur, motif) et ne recalcule que les exercices ouverts. La VNC au début du premier exercice ouvert est répartie sur la durée restante recalculée (voir cas A6).
- **RG-38.** Un plan est « validé » quand le comptable le valide ou à la clôture de l'exercice. Chaque validation crée une version conservée.
- **RG-39.** À la sortie d'un bien amortissable, la dotation de l'exercice est arrêtée à la date de sortie incluse ; la valeur d'origine, le cumul et la VNC à cette date sont figés sur la sortie (voir cas A5).
- **RG-40.** Les cas de `tests/Fixtures/amortissements.json` font foi. Un écart entre le code et ces cas est un bug du code, sauf décision écrite du comptable.

## Clôture d'exercice

- **RG-41.** Seuls l'administrateur et le comptable peuvent clôturer un exercice, à partir du dernier jour de l'exercice.
- **RG-42.** Avant clôture, l'application affiche les points d'attention : sorties non validées par le comptable, inventaires non clôturés. Ils n'empêchent pas la clôture mais doivent être confirmés.
- **RG-43.** La clôture fige les lignes de l'exercice, ouvre l'exercice suivant s'il n'existe pas et ne peut pas être annulée dans l'application.

## Inventaires physiques

- **RG-44.** Un inventaire porte sur un périmètre : site, département, service, catégorie, local ou tout le patrimoine (éventuellement croisé avec un site). La liste des biens attendus est figée au démarrage.
- **RG-45.** Pour chaque bien attendu, le résultat est : présent, manquant, présent mais affecté ailleurs, présent avec un état différent, ou justifié (prêté ou en maintenance au démarrage).
- **RG-46.** Un bien scanné qui n'est pas dans la liste attendue est enregistré comme « présent hors périmètre ». Un bien trouvé sans fiche est enregistré comme « non enregistré » avec une description ; on peut ensuite créer sa fiche.
- **RG-47.** La saisie est enregistrée au fil de l'eau ; plusieurs personnes peuvent saisir le même inventaire en même temps.
- **RG-48.** La clôture d'un inventaire fige ses résultats (rapport conservé). Elle ne modifie pas les fiches automatiquement : les régularisations (transfert, mise à jour d'état, sortie pour perte) se font ensuite, depuis le rapport, chacune tracée.
- **RG-49.** Le rapport d'inventaire s'exporte en PDF (procès-verbal avec emplacements de signature) et en Excel.

## Sorties du patrimoine

- **RG-50.** Un bien quitte le patrimoine uniquement par une sortie : cession, don, réforme, destruction, perte, vol, autre motif. Il n'est jamais supprimé.
- **RG-51.** Une sortie enregistre : date, motif, responsable de la décision, référence de la décision, observations, justificatifs, et pour une cession l'acquéreur et le prix.
- **RG-52.** La sortie passe le statut à Cédé (cession, don) ou Réformé (autres motifs), arrête l'amortissement (RG-39) et rend la fiche consultable en lecture seule.
- **RG-53.** La sortie est confirmée explicitement (saisie du numéro du bien). Elle ne peut pas être annulée dans l'application.
- **RG-54.** Le traitement comptable de la sortie est marqué « à valider » jusqu'à validation par le comptable.

## Prêts et emprunts

- **RG-55.** Un prêt concerne un ou plusieurs biens disponibles (statut En service ou En stock). Il enregistre bénéficiaire (interne ou externe, avec contact), date de sortie, date de retour prévue, état au départ, responsable, observations.
- **RG-56.** Pendant le prêt, le statut des biens est Emprunté. Au retour, on enregistre la date effective, l'état au retour et les observations ; le statut revient à sa valeur précédente.
- **RG-57.** Si l'état au retour est moins bon que l'état au départ, l'état du bien est mis à jour et l'application propose d'ouvrir une intervention.
- **RG-58.** Un emprunt par l'Église (bien d'un tiers) est enregistré à part, sans numéro patrimonial (référence `EXT-0001`), et n'entre pas dans le patrimoine ni dans les amortissements.

## Maintenance et signalements

- **RG-60.** Une intervention enregistre bien, nature, motif, dates, intervenant (interne ou prestataire), coût, résultat, observations, justificatifs.
- **RG-61.** À l'ouverture, on peut passer le bien En maintenance. À la clôture, on choisit le nouvel état et le nouveau statut. Un résultat « irréparable » propose une sortie.
- **RG-62.** Le coût des interventions n'entre pas dans la valeur amortissable du bien.
- **RG-63.** Un responsable de département peut signaler, sur un bien de son département, un changement d'état, un déplacement ou un besoin de maintenance. Un signalement ne modifie pas le bien.
- **RG-64.** Le gestionnaire traite le signalement en créant l'opération correspondante (intervention, transfert, mise à jour d'état ou sortie), ou le rejette avec un motif. Le responsable voit le statut de ses signalements.

## Documents

- **RG-65.** Un document est rattaché à un ou plusieurs objets : bien, acquisition, mouvement, inventaire, intervention, prêt, sortie, plan d'amortissement. Il a un type (liste paramétrable).
- **RG-66.** Les fichiers sont stockés sur le serveur local, hors du dossier public, et servis après contrôle des droits. Taille maximale par défaut 20 Mo ; formats PDF, images, Word, Excel.

## Journal des opérations

- **RG-70.** Sont tracées : création et modification d'un bien, affectation, transfert, inventaire (démarrage, saisie, clôture), maintenance, prêt et retour, sortie, modification d'un paramètre d'amortissement, validation d'un plan, clôture d'exercice, connexion, gestion des utilisateurs et des rôles.
- **RG-71.** Chaque entrée garde l'utilisateur, la date et l'heure, le poste (adresse IP locale), la nature de l'opération, l'objet concerné et les valeurs avant et après.
- **RG-72.** Le journal n'est ni modifiable ni supprimable depuis l'application.

## Droits d'accès

- **RG-80.** Rôles livrés : Administrateur, Gestionnaire du patrimoine, Comptable, Responsable de département, Consultation. Les droits sont paramétrables par l'administrateur (matrice module × consulter, créer, modifier, sortir, valider, exporter).
- **RG-81.** Un utilisateur a un périmètre : tous les sites ou certains, tous les départements ou certains. Le périmètre filtre toutes les listes, recherches, rapports et exports.
- **RG-82.** Le responsable de département consulte les biens de son périmètre, signale, participe aux inventaires auxquels il est désigné et consulte les rapports autorisés. Il ne modifie aucune fiche.
- **RG-83.** Seuls l'administrateur et le comptable modifient les règles d'amortissement, valident les plans et les sorties, clôturent les exercices.
- **RG-84.** Un compte se désactive, il ne se supprime pas. Un compte désactivé ne peut plus se connecter et garde son historique.
- **RG-85.** Mot de passe de 8 caractères minimum avec un chiffre ; blocage 15 minutes après 5 échecs ; déconnexion après 30 minutes d'inactivité (paramétrable). Mot de passe oublié : réinitialisation par l'administrateur, avec mot de passe provisoire à changer à la première connexion (pas d'e-mail en V1).

## Accès simultanés

- **RG-86.** Deux utilisateurs qui modifient la même fiche : le second à enregistrer est prévenu que la fiche a changé depuis son ouverture (contrôle par date de mise à jour) et doit recharger. Aucun écrasement silencieux.

## Alertes

- **RG-90.** Alertes internes affichées dans l'application (pas d'e-mail ni de SMS en V1) : prêt arrivant à échéance (3 jours par défaut), retard de retour, intervention ouverte depuis plus de 15 jours, bien déclaré hors service, inventaire planifié (7 jours avant), écarts d'inventaire non régularisés, fin d'amortissement (3 mois avant). Les délais sont paramétrables.
- **RG-91.** Une alerte disparaît quand sa cause est levée. Chaque utilisateur peut marquer une alerte comme lue.

## Étiquettes

- **RG-95.** Une étiquette contient au minimum le sigle de l'Église, le numéro patrimonial et un QR code ou un code-barres (Code 128) qui ne contient que le numéro. Désignation, département, site et local sont optionnels selon le modèle.
- **RG-96.** Formats livrés : planche A4 3 × 8 (70 × 37 mm) et rouleau 50 × 25 mm. On peut commencer une planche à une case donnée pour réutiliser une planche entamée.
- **RG-97.** Impression unitaire, en lot (sélection depuis la liste des biens) et réimpression. Aperçu avant impression obligatoire. L'impression se fait depuis un PDF généré à l'échelle exacte.

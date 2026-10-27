---
description: Relit la branche en cours avant fusion
---

Relis les changements de la branche en cours par rapport à `main` comme un développeur senior exigeant.

Vérifie en priorité :

1. **Règles de gestion** : chaque RG citée par le lot est respectée et testée. Aucune règle inventée.
2. **Droits** : chaque ressource, page et action a sa Policy ; un responsable de département ne peut ni voir ni modifier hors de son périmètre (essaie de le prouver par un test).
3. **Intégrité** : numéro patrimonial unique même en concurrence, rien de supprimable qui ne devrait pas l'être, exercices clôturés intouchables, montants en entiers.
4. **Traçabilité** : les opérations de RG-70 écrivent bien dans le journal avec avant et après.
5. **Hors ligne** : aucune URL externe (CDN, polices, API) dans le code ou les vues.
6. **Interface** : libellés en français, navigation conforme à `docs/04`, messages d'erreur compréhensibles par un non-informaticien.
7. **Qualité** : tests, Pint et Larastan passent ; pas de logique métier dans les ressources Filament.

Rends une liste courte : bloquant, à corriger, suggestion. Corrige directement ce qui est bloquant si c'est simple, sinon explique.

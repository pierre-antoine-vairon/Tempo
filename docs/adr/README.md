# ADR — décisions d'architecture

Les ADR de ce dossier retracent des décisions prises à différentes étapes de Tempo.

Ils restent utiles pour comprendre le raisonnement historique, mais **ils ne sont pas tous l'état d'implémentation actuel**.

En cas de contradiction, utiliser cet ordre :

1. code réellement présent dans le working tree ;
2. `docs/project-status.md` ;
3. `docs/decisions.md` ;
4. `docs/production_rules.md` pour Production ;
5. ADR.

Points connus :

- ADR-001 : principe Site / Organisation toujours pertinent ;
- ADR-002 : JWT / RBAC est une cible historique dont l'implémentation est actuellement reportée ;
- ADR-003 : backend source de vérité, client API centralisé et scoping multi-tenant restent des principes actifs ;
- ADR-004 et ADR-005 : orientations Planning toujours utiles mais développement Planning actuellement en pause.

Ne jamais exécuter une migration, un seed ou une modification de schéma décrite dans un ADR sans validation explicite et sans comparer avec l'état réel de la base.

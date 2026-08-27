# ADR-001 – Modélisation des Sites et Organisation du Planning

## Contexte

Dans une société de services (nettoyage, santé, ambulance…), les plannings concernent plusieurs lieux distincts.  
Chaque lieu a ses propres besoins (horaires, nombre d’agents, compétences).  
Problème : faut-il gérer un seul planning global ou plusieurs par site ?

## Décision

- Introduire l’entité **Site** = unité organisationnelle correspondant à un lieu physique où des shifts peuvent être définis.
- Chaque Site est lié à une Organisation (le client).
- Les plannings sont générés **par Site** mais une vue globale **filtrable** est disponible pour le manager.

## Conséquences

- **Avantages :** modèle générique multi-secteurs, flexible (nombre de shifts/agents paramétrable).
- **Inconvénients :** complexité plus élevée que “un seul planning”.
- **Impact technique :** nécessite une table `sites` reliée à `shifts` et `demands`.

## Status

Validé – Étape 1 (Atelier Métier).

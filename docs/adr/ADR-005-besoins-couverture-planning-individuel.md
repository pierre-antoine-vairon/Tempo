# ADR-005 – Besoins, couverture calculée et planning individuel

## Status

Validé – Orientation produit MVP / IA-ready.

## Contexte

Tempo ne doit pas penser un planning uniquement comme une liste de shifts ou uniquement comme une grille d’horaires individuels. Trois niveaux distincts mais complémentaires sont nécessaires :

```txt
Besoins
→ ce dont le site a besoin

Couverture calculée
→ combien de personnes sont nécessaires / présentes par tranche

Planning individuel
→ qui travaille quand
```

## Décision

Tempo intègre les trois couches :

```txt
Besoins → Couverture calculée → Planning individuel
```

### Vue Besoins

Permet de saisir ou visualiser les besoins opérationnels sous forme de blocs horaires. Elle sert notamment à construire une organisation type, calculer un volume d’heures et préparer des recrutements.

### Vue Couverture

Calcule la couverture par tranches horaires à partir des besoins et/ou du planning réel. Elle doit rendre visibles sous-couverture, sur-couverture, pics et trous.

### Vue Planning employés

Vue opérationnelle principale :

```txt
Worker → Jour → Horaire
```

À terme, elle doit permettre de modifier les horaires individuels.

## Positionnement du modèle actuel

Le modèle MVP reste :

```txt
Roster → Shift → Assignment → Worker
```

Le roster représente une période, le shift porte l’horaire et l’assignment relie un worker au shift. Ce modèle est conservé pour avancer.

Une évolution future vers des horaires individualisés au niveau de l’affectation pourra être réévaluée (`y_assignments.starts_at`, `y_assignments.ends_at` ou table dédiée), mais elle n’est pas décidée maintenant.

## Rôle futur de l’IA

L’IA ne remplace pas la logique métier. Elle doit utiliser des calculs déterministes fournis par Tempo.

```txt
L’IA propose.
Tempo calcule et vérifie.
Le manager décide.
```

Le mode manuel reste obligatoire pour toutes les opérations essentielles.

## Décision court terme historique

L’ordre de travail Planning envisagé était : conserver le modèle actuel, documenter Besoins/Couverture/Planning, construire une couverture à partir des shifts et reporter l’évolution des horaires d’assignment.

Le développement Planning est actuellement en pause pendant la consolidation de la chaîne opérationnelle Production–Inventaire–Commandes–Coûts. Voir `docs/project-status.md` et `docs/roadmap.md` pour la priorité courante.

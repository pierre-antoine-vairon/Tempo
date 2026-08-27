# ADR-004 – Vue Planning Manager et Politique d’Affectation

## Status

Validé – MVP Tempo.

## Contexte

Tempo est une application SaaS de planning multi-site / multi-workers.

Le modèle actuel de données est structuré autour de la chaîne :

```txt
Roster → Shift → Assignment → Worker
```

Dans ce modèle :

- un **Roster** représente une période de planning pour une organisation et un site ;
- un **Shift** représente un créneau horaire concret à couvrir ;
- une **Assignment** relie un worker à un shift ;
- un **Worker** représente une personne affectable.

Cette structure permet de construire un planning à partir de besoins horaires par site.

Cependant, dans l’usage réel d’un manager de restauration, la vue de travail la plus naturelle est souvent une vue **par employé et par jour** :

```txt
Employé      Lundi          Mardi          Mercredi
John         07:30-15:30    07:30-15:30    Repos
Marie        07:30-15:30    Repos          07:30-15:30
Clara        10:00-18:00    10:00-16:00    10:00-18:00
```

Il faut donc clarifier la relation entre le modèle technique actuel centré sur les shifts et la future expérience utilisateur centrée sur la lecture du planning par employé.

## Décision

Pour le MVP, Tempo conserve le modèle actuel :

```txt
Roster → Shift → Assignment → Worker
```

### 1. Le Shift porte l’horaire

Dans le MVP, l’horaire travaillé par un worker vient du shift auquel il est assigné.

### 2. L’Assignment relie un worker à un shift

Une assignment ne porte pas encore ses propres horaires. Elle indique uniquement :

```txt
worker X est affecté au shift Y
```

### 3. La vue manager cible doit aussi être une vue par employé

Même si le modèle technique actuel est centré sur les shifts, l’interface manager doit évoluer vers une lecture plus opérationnelle :

```txt
Employé → Jour → Horaire
```

### 4. Les horaires individualisés par assignment sont une évolution future possible

Le modèle actuel impose que plusieurs workers assignés au même shift partagent le même horaire. À moyen terme, si le besoin est confirmé, Tempo pourra faire évoluer le modèle pour permettre des horaires portés par l’assignment. Cette évolution n’est pas retenue pour le MVP actuel.

## Politique sur `required_count`

`required_count` représente un objectif de couverture, pas une limite bloquante.

```txt
required_count = 2
assignés = 1 → sous-couvert
assignés = 2 → couverture OK
assignés = 3 → sur-couvert
```

Le manager peut volontairement affecter plus ou moins de personnes que l’objectif prévu.

## Politique sur les règles métier

Pour le MVP, les règles métier suivantes ne sont pas bloquantes : repos insuffisant, compétence manquante, heures contractuelles dépassées ou insuffisantes, dépassement du `required_count`.

```txt
Tempo signale.
Le manager décide.
```

## Règles qui restent bloquantes

Les erreurs d’intégrité restent bloquantes côté backend : `worker_id` ou `shift_id` inexistant, cross-org, assignment dupliquée, payload invalide ou violation du scoping `org_id`.

## Décision court terme

1. conserver le modèle actuel ;
2. créer une vue `/planning` par employé en lecture seule ;
3. ne pas modifier la structure DB maintenant ;
4. traiter les règles métier comme des warnings ;
5. garder les validations d’intégrité côté backend.

## Décision future à réévaluer

```txt
Faut-il faire évoluer y_assignments pour porter starts_at / ends_at ?
```

Cette décision dépendra de l’usage réel du planning par employé.

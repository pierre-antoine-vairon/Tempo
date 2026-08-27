# Tempo — Décisions structurantes

Dernière mise à jour : **27/08/2026**

Ce document résume les décisions encore valides. Les ADR conservent le détail historique.

## Socle technique

- **Multi-tenant** : `org_id` sur les données métier ; préfixe de tables `y_`.
- **Multi-site** : `site_id` porte le scope établissement lorsque nécessaire.
- **DB** : MariaDB compatible MySQL, InnoDB, `utf8mb4_unicode_ci`.
- **Temps** : stockage technique en UTC ; conversion à l'affichage selon le contexte.
- **Audit** : `created_by`, `created_at`, `updated_by`, `updated_at` lorsque applicable.
- **Conventions** : snake_case, FK en `_id`.
- **Backend source de vérité** : Laravel contrôle les écritures et l'intégrité ; le frontend guide l'utilisateur.
- **Pas de migrations Laravel pour l'instant** : évolutions SQL directes via MySQL Workbench + scripts versionnés dans `db/schema/`.
- **Base distante partagée** : aucune opération destructive, reset, migration, seed ou modification arbitraire du schéma sans validation explicite.
- **Préserver l'existant** : tables, contraintes, triggers et données historiques ne doivent jamais être supposés recréables ou supprimables.

## Principe produit général

Tempo doit rester utilisable en mode manuel. L'IA assiste l'utilisateur mais ne remplace ni les règles métier déterministes ni le fonctionnement de base.

```text
Intégrité technique → bloquante
Erreur / oubli métier récupérable → warning ou confirmation lorsque possible
```

## Source de vérité de développement

Dans un environnement local de développement :

1. working tree local / `git status` / `git diff` ;
2. code commité dans le dépôt Tempo ;
3. documentation actuelle de `docs/` ;
4. scripts de `db/schema/` ;
5. décisions historiques / ADR.

En cas de contradiction, la divergence doit être signalée avant toute modification structurante.

# Planning

Le modèle MVP reste :

```text
Roster → Shift → Assignment → Worker
```

Tempo distingue conceptuellement besoins, couverture calculée et planning employés. `required_count` est un objectif, pas une limite bloquante.

Repos, compétences, heures contractuelles et couverture restent des warnings dans le MVP lorsque la décision peut rester au manager. Les incohérences techniques sont bloquantes : entité inexistante, cross-org, payload invalide, doublon incohérent.

JWT / RBAC ont été étudiés mais restent **reportés**. Les documents plus anciens qui les présentent comme actifs décrivent une cible historique.

# Production

## Production V1

V1 considérée fonctionnellement terminée : Vue d'ensemble, Production du jour, Historique et Produits.

Cycle :

```text
not_started → in_progress → finished → closed
```

- `in_progress` : saisie encore modifiable ;
- `finished` : journée opérationnellement terminée ;
- `closed` : validation / verrouillage manager.

`finished` suffit à alimenter la suite de l'exploitation ; `closed` n'est pas requis pour commencer la journée suivante.

## NULL vs 0

Pendant `in_progress`, `NULL` signifie non renseigné et `0` zéro confirmé. Lors de `finished`, Tempo peut convertir les valeurs applicables encore `NULL` en `0` **uniquement après confirmation explicite**.

## Historique produits

Une journée utilise un snapshot dans `y_production_day_products`. Modifier le catalogue courant ne doit jamais modifier une ancienne feuille.

## Catalogue

```text
Famille → Catégorie → Produit
```

Activation, désactivation et ordre concernent le catalogue courant et les futures feuilles.

## Conservation J

Un produit `J` ne reporte jamais de stock : `stock_previous = NULL`.

## Stock J-1 V1

La V1 utilise encore la veille calendaire exacte. Une journée ouverte ne doit jamais être réécrite silencieusement si la journée précédente change : vérification / actualisation explicite.

## Calendrier d'exploitation — prochaine fondation

Tempo doit distinguer jour ouvert / fermé, règle habituelle et exception datée. Une exception datée gagne sur la règle habituelle. Un changement durable doit à terme pouvoir avoir une date d'effet pour préserver l'historique.

## Continuité — cible

Une nouvelle journée ne doit pas démarrer si la journée d'exploitation précédente pertinente est encore `in_progress`. `finished` ou `closed` permettent de continuer. Un jour fermé sans feuille ne casse pas la chaîne. Un jour normalement ouvert sans feuille ne doit pas être sauté silencieusement : il faut résoudre l'anomalie ou enregistrer une fermeture exceptionnelle.

## Stock entre journées — cible

Passer de la veille calendaire à la **dernière journée d'exploitation pertinente et finalisée**.

## FIFO / âge — cible

Pour les produits `J+N`, Tempo suivra des lots virtuels datés et imputera les sorties au stock le plus ancien en premier. Le temps calendaire continue de vieillir le produit même quand le site est fermé.

# Direction produit

Tempo ne vise pas à copier un autre outil. Sa proposition de valeur cible est :

> **Tempo aide le responsable à savoir quoi produire, quoi commander et pourquoi, à partir de l'activité réelle du point de vente.**

Boucle centrale :

```text
Production réelle → consommation / pertes → inventaire → besoins → commande → réception → coûts / marges → recommandations
```

Après les fondations Production : inventaires, fournisseurs, commandes/réceptions, matières et mouvements de stock, fiches techniques/rendements, consommation théorique vs réelle, pertes/écarts/coûts/food cost, recommandations, puis première couche HACCP. Planning / heures / RH seront repris ensuite.

# ADR existants

- ADR-001 : Sites & Planning.
- ADR-002 : Authentification JWT / RBAC — cible étudiée, implémentation reportée.
- ADR-003 : API / Front / règles métier.
- ADR-004 : Vue planning manager et politique d'affectation.
- ADR-005 : Besoins / couverture / planning individuel — document historique de conception.

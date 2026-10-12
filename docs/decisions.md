# Tempo — Décisions structurantes

Dernière mise à jour : **12/10/2026**

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

## Stock précédent

Lorsque le calendrier n'est pas configuré pour la date courante, Tempo conserve le J-1 calendaire historique. Lorsqu'il est configuré, le stock précédent vient du dernier jour d'exploitation attendu avant la date courante.

Une journée ouverte ne doit jamais être réécrite silencieusement si la journée précédente change : la vérification et l'actualisation du stock précédent restent explicites.

## Calendrier d'exploitation

Le calendrier est composé de versions hebdomadaires complètes de 7 jours, datées par `effective_from` et append-only. Des exceptions datées permettent une ouverture ou une fermeture exceptionnelle.

La résolution suit cet ordre : exception datée, dernière règle hebdomadaire applicable, puis calendrier non configuré. La première `effective_from` est la frontière d'activation ; avant elle, Production conserve le comportement historique.

Une nouvelle feuille Production est refusée un jour configuré fermé. Une ouverture exceptionnelle doit être enregistrée dans le calendrier avant de créer la feuille. Une feuille historique existante reste lisible même si le calendrier indique fermé.

## Continuité

Une nouvelle journée peut être créée quand la continuité de stock est indisponible, mais aucun stock précédent n'est alors repris. Le premier jour d'exploitation ouvert attendu qui est absent, `in_progress` ou dans un statut invalide interrompt la continuité ; Tempo ne recherche pas une journée plus ancienne.

`finished` et `closed` sont exploitables. Un jour fermé sans feuille ne casse pas la chaîne.

## Stock entre journées

La journée précédente est le dernier jour d'exploitation attendu et exploitable lorsque le calendrier est configuré. S'il n'existe aucun jour ouvert précédent entre la date courante et la frontière d'activation, aucun fallback historique n'est appliqué.

Les produits `J` gardent `stock_previous = NULL`, et `stock_end = NULL` reste `stock_previous = NULL`.

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

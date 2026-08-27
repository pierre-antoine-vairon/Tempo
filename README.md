# Tempo

Tempo est un SaaS de pilotage opérationnel pour les responsables de point de vente.

La proposition de valeur cible est :

> **Tempo aide le responsable à savoir quoi produire, quoi commander et pourquoi, à partir de l'activité réelle du point de vente.**

## Structure du dépôt

```text
Tempo/
├── backend/              # API Laravel
├── frontend/             # React / TypeScript / Vite
├── docs/                 # documentation métier et technique actuelle
└── db/schema/            # scripts SQL versionnés
```

`AGENTS.md` sera ajouté séparément après validation pour encadrer l'utilisation de Codex et des agents de développement.

## État actuel

Le module **Production V1 est terminé** :

- Vue d'ensemble ;
- Production du jour ;
- Historique ;
- Produits ;
- cycle `not_started → in_progress → finished → closed` ;
- snapshots historiques ;
- distinction `NULL` / `0` ;
- Stock J-1 et actualisation explicite ;
- catalogue Famille → Catégorie → Produit.

La prochaine fondation est le **calendrier d'exploitation du site**, puis la continuité des journées et le report du stock depuis la dernière journée d'exploitation pertinente.

Voir :

- [`docs/project-status.md`](docs/project-status.md) — état courant ;
- [`docs/roadmap.md`](docs/roadmap.md) — trajectoire produit ;
- [`docs/production_rules.md`](docs/production_rules.md) — règles Production ;
- [`docs/decisions.md`](docs/decisions.md) — décisions structurantes ;
- [`db/README.md`](db/README.md) — règles de sécurité SQL.

## Stack

- Backend : Laravel / PHP 8.2 ;
- Frontend : React + TypeScript + Vite ;
- Base : MariaDB / MySQL ;
- multi-tenant : `org_id` ;
- multi-site : `site_id` ;
- pilote actuel : `org_id = 1`, `site_id = 1`.

## Base de données — règle impérative

La base utilisée pendant le développement est distante et partagée.

**Ne jamais exécuter automatiquement un script de `db/schema/`.**

Aucune migration, seed, `DROP`, `TRUNCATE`, reset ou autre opération destructive ne doit être exécutée sans décision explicite. Les scripts SQL sont versionnés pour conserver la trace des évolutions et sont appliqués volontairement via MySQL Workbench.

Certains anciens scripts importés depuis `AppPlanning-docs` décrivent l'histoire du schéma et ne sont pas garantis reproductibles depuis une base vide. Lire impérativement `db/README.md` avant toute utilisation.

## Documentation historique

Le dépôt `AppPlanning-docs` reste conservé sur GitHub pour l'historique complet du projet. À partir du 27/08/2026, la documentation courante utile au développement est centralisée dans ce dépôt `Tempo`.

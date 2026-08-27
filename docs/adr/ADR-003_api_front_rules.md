# Règles MVP – API Laravel, Front React (Vite) et logique métier

## Pourquoi ce document

Tu construis Tempo (AppPlanning) en mode **MVP** : aller vite vers un CRUD utilisable (rosters → shifts → assignments) **sans te perdre** ni créer une dette qui t’oblige à tout refaire plus tard.

Ce document fixe les règles simples qu’on applique **dès maintenant**.

---

## Définitions (en simple)

### Logique métier

Ce sont les **règles de gestion “réelles”** du planning (vrai monde), ex :

- pas de chevauchement de shifts pour un worker,
- temps de repos minimum,
- compétences requises,
- statuts (draft/published),
- contraintes contractuelles, etc.

➡️ Ces règles ne sont pas “de l’UI”, ce sont des règles qui doivent rester cohérentes partout.

### DTO (Data Transfer Object)

C’est la **forme standard** des données échangées entre l’API et le front (les champs + leurs noms).
Ex : un `WorkerDTO` peut être `{ id, org_id, first_name, last_name, site_id }`.

---

## Règles à respecter (MVP)

### R1 — Le backend (Laravel + DB) est la **source de vérité**

- Ce qui est “vrai” vient de la DB via l’API.
- Le front n’invente pas d’état “réel” divergent.

### R2 — Pas de logique métier “décisive” côté front

Le front peut faire du confort UX, mais **le backend valide toujours**.

### R3 — Scoping multi-tenant : toujours filtrer par `org_id`

Tant qu’il n’y a pas d’auth, on utilise un **org_id par défaut** :

- `.env` : `TEMPO_DEFAULT_ORG_ID=1`
- Code : `config('tempo.default_org_id')` (via `config/tempo.php`)

Objectif : garder le réflexe multi-tenant dès le MVP.

### R4 — Un seul “client API” côté front

Ne pas multiplier les `fetch()` : centraliser baseURL, erreurs et plus tard token.

### R5 — DTO simples et stables

Pour le MVP, renvoyer des champs minimaux et stables.

---

## Contexte historique

Ce document a été rédigé pendant le travail Planning. Les principes R1–R5 restent utiles, mais les sections décrivant l'état exact des endpoints à cette date sont historiques. Pour l'état courant, voir `docs/project-status.md` et le code réel.

# ADR-002 — Authentification & RBAC multi-tenant

> **Note actuelle (27/08/2026) :** cet ADR décrit une cible étudiée et acceptée historiquement, mais son implémentation est **reportée** dans le MVP actuel. Voir `docs/project-status.md` et `docs/decisions.md` pour l'état courant.

## Statut

Accepté (v1) — implémentation reportée

## Contexte

Tempo est un SaaS multi-tenant : chaque utilisateur appartient à une organisation (`org_id`) et ne doit voir que ses données. On expose une API (Laravel) consommée par une app mobile/web. Besoin : authentifier, autoriser par rôles/permissions, et s’assurer que `org_id` n’est jamais “imposé” par le client. (Version draft antérieure : JWT + 3 rôles + middleware + is_active. Consolidation ici.)

## Problème

- Sécuriser l’accès API (mobile/web), scalable.
- Appliquer un **RBAC** (roles→permissions) clair.
- **Isoler** strictement par `org_id`.

## Décision

1. **Tokens** : JWT (access + refresh).
2. **Signature** : HS256 via secret d’environnement (rotation possible par `kid`).
3. **Durées** : access = 15 minutes ; refresh = 14 jours (rotation au refresh).
4. **Payload JWT (access)** : `sub` (user_id), `org_id`, `roles[]`, `iat`, `exp`, `kid`.
5. **RBAC** : Rôles → Permissions (pivots). Contrôles via middleware/policies.
6. **Multi-tenant** : Middleware `inject.org` : l’API **détermine** `org_id` depuis le token et **ignore** tout `org_id` transmis par le client.
7. **Revocation** : Table de refresh tokens (liste noire via `jti` en cas de vol).
8. **Sécurité** : Mot de passe Argon2id ; rate limit login ; CORS whitelist. Pas de CSRF (API pure, JWT en header).
9. **Comptes** : `y_users.is_active` (désactivation soft).

## Alternatives envisagées (rejetées)

- Sessions serveur (stateful) : plus complexe à scaler et à gérer pour mobile.
- OAuth2/OIDC dès maintenant : surdimensionné pour v1.

## Détails de mise en œuvre

- **Tables**
  - `y_roles (id, org_id NULLable, code, name)` → permet des rôles globaux (org_id NULL) ou org-scoped.
  - `y_permissions (id, code UNIQUE, name)`
  - `y_user_roles (user_id, role_id, org_id)` PK composite
  - `y_role_permissions (role_id, permission_id)` PK composite
  - `y_refresh_tokens (id, user_id, org_id, jti, revoked, expires_at, created_at)`
  - `y_users` : champs existants + `is_active TINYINT(1)` + `email_verified_at` (optionnel v1).
- **Rôles v1** : `OrgAdmin`, `SiteManager`, `Worker`.
- **Permissions minimales v1** : `roster.read`, `roster.write`, `shift.read`, `shift.write`, `assignment.write`, `worker.read`.
- **Middlewares**
  - `auth.jwt` : vérifie signature/exp/`kid`.
  - `inject.org` : attache `request.org_id` depuis le JWT (et refuse mismatch).
  - `perm:<permission>` : vérifie la permission effective (via roles→permissions).
- **Journaux** : login/logout/refresh (IP, UA), refus 401/403.

## Impacts API (v1)

- `POST /auth/login` → {access, refresh}
- `POST /auth/refresh` → {access, refresh}
- `POST /auth/logout` → 204
- Exemples protégés : `GET /rosters?site_id=&from=&to=`
- Erreurs normalisées : 401 (auth), 403 (permission/org).

## Migration & seed

- Créer les 5 tables RBAC/refresh.
- Seeder 3 rôles + matrice de permissions minimale + assigner `OrgAdmin` au compte admin de la demo.

> Cette section est historique : **ne pas exécuter ces migrations/seeds sur la base actuelle sans décision explicite.**

## Tests (à automatiser)

- Unit : parsing/validation JWT, policies.
- Intégration : cross-org refusé ; rotation refresh OK.
- E2E : login → GET rosters (200) ; cross-org (403).

## Risques & mitigations

- Vol de refresh : rotation + blacklist par `jti` + contrôle IP/UA.
- `org_id` fourni par client : **ignoré** (source unique = token).

## KPIs de sécurité

- Taux 401/403, temps moyen login, incidents sécurité (objectif = 0).

## Contexte d’organisation (V1)

- Le login exige un `org_code` (slug unique de l’organisation).
- Le client mémorise le dernier `org_code` utilisé par l’utilisateur (stockage local).
- Règles d’UX :
  - 1ère connexion sur un appareil : `org_code` requis.
  - Connexions suivantes : si une seule org connue, sélection automatique ; si plusieurs, pré-sélection de la dernière + sélecteur manuel.
  - Un écran "Changer d’organisation" permet de passer d’une org à l’autre sans se déconnecter globalement (nouvelle session, nouveau JWT).
- Le JWT émis inclut `org_id`. L’API ignore tout `org_id` fourni par le client et impose celui du token (middleware `inject.org`).

## Conflits inter-org (V1)

- Décision : non pris en charge.
- Hypothèse : le worker communique ses indisponibilités à chaque employeur.
- Piste V2 : alerte **privée** au worker (jamais inter-clients).

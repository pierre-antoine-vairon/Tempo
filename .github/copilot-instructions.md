# Tempo — Copilot Instructions

## Project context

Tempo is a SaaS workforce planning app.
The backend is Laravel REST API.
The frontend is React + Vite + TypeScript.
The project is currently in MVP mode.

## Current architecture

- `backend/` contains the Laravel API.
- `frontend/` contains the React/Vite dashboard.
- Backend Laravel is the source of truth.
- Frontend consumes the backend API.
- Do not create a second backend inside the frontend.
- Do not use Next.js for this MVP.

## MVP rules

- Do not add authentication yet.
- Do not add RBAC/JWT yet.
- Do not add complex architecture unless explicitly requested.
- Do not invent API routes.
- Use existing API routes when possible.
- Existing API routes:
  - GET /api/health
  - GET /api/sites
  - GET /api/workers

## Frontend rules

- Use React + TypeScript.
- Avoid `any`.
- Use explicit types for API responses.
- Use the existing `src/api.ts` client for API calls.
- Do not scatter `fetch()` calls everywhere.
- Keep components small and readable.
- Do not add dependencies without explaining why.

## Backend rules

- Use Laravel API routes.
- Do not create Laravel migrations for now.
- The database already exists.
- Tables use `y_*` naming.
- Multi-tenant scoping is done with `org_id`.
- Until authentication exists, use the default org id from config/env.

## Business rules

- Do not put decisive business rules in React.
- Backend/API/DB remain the source of truth.
- Workers may have `site_id = null`.
- A worker’s actual work site comes from shifts and assignments, not necessarily from `y_workers.site_id`.

## AI usage rules

- Prefer small, incremental changes.
- Do not rewrite large parts of the project without being asked.
- If a route, table, or field is unknown, ask instead of inventing.
- For every code suggestion, explain how to test it.

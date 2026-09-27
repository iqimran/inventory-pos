# Module 01 — Foundation & Authentication

## Scope
- Laravel 12 project foundation.
- MySQL configuration.
- Sanctum.
- React/Inertia/TypeScript/Tailwind setup.
- User authentication.
- Roles and permissions.
- Admin/general-user baseline.
- Audit fields/utilities.
- Application settings if needed.

## Data
users
roles
permissions
model_has_roles / model_has_permissions as provided by authorization package

Optional:
settings

## Rules
- Admin has full access.
- General User receives only explicitly granted operational permissions.
- Authorization must use policies/permissions, not frontend hiding alone.

## Acceptance criteria
- Login/logout works.
- Protected pages require authentication.
- Admin can manage users/permissions.
- Unauthorized API/page actions return appropriate authorization responses.

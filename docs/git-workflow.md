# Git, Release & Deployment Workflow

## Branches

| Branch | Purpose | Deploys to |
|---|---|---|
| `main` | Production-ready code. Every commit on `main` has passed CI; releases are tagged here. | Production (by tag) |
| `develop` | Integration of finished work before a release. | Staging (optional) |
| `feature/<topic>` | New work, branched from `develop`. e.g. `feature/product-management`, `feature/mobile-service` | — |
| `fix/<topic>` | Bug fixes, branched from `develop`. e.g. `fix/negative-stock` | — |
| `hotfix/<topic>` | Urgent production fixes, branched from `main` and merged back into `main` **and** `develop`. | Production |

Create `develop` from `main` once (`git switch -c develop main && git push -u origin develop`) and use it as
the base for all feature and fix branches from then on.

Flow: `feature/*` → pull request → `develop` → (release) pull request → `main` → tag `vX.Y.Z` → deploy.

CI (`.github/workflows/tests.yml`, `lint.yml`) runs on pushes and pull requests to `main` and `develop`:
the full PHPUnit suite, frontend build, Pint, Prettier and ESLint. Merge only when it is green.

## Commits: Conventional Commits

```
<type>: <what changed, imperative, lower case>

<optional body: why, and anything a reviewer or operator must know>
```

| Type | Use for | Example |
|---|---|---|
| `feat` | New user-facing capability | `feat: implement product management` |
| `fix` | Bug fix | `fix: prevent negative stock` |
| `perf` | Measured performance improvement | `perf: paginate party ledger statement` |
| `refactor` | Code change without behaviour change | `refactor: extract stock movement service` |
| `test` | Tests only | `test: add purchase transaction tests` |
| `docs` | Documentation only | `docs: update deployment guide` |
| `chore` | Tooling, build, Docker, dependencies | `chore: configure docker environment` |
| `style` | Formatting only (Pint/Prettier) | `style: apply pint` |

Rules:

- One logical change per commit; the suite passes at every commit.
- Say what changed, not "update", "changes", "final", "work" or "wip".
- Mention the task (e.g. `T047`) in the body when the commit completes one.
- **Never commit** `.env` or any `.env.*` other than `.env.example`, keys (`*.key`, `*.pem`), database
  dumps or `backups/`. These are git-ignored; check `git status` before committing anyway. If a secret is
  ever committed, rotate it immediately (removing it from history does not un-leak it).
- **Never rewrite shared history**: no `git push --force` to `main` or `develop`, no rebasing pushed
  branches others use. Undo with a new commit (`git revert`).

## Releases and tags

Versions follow Semantic Versioning: `vMAJOR.MINOR.PATCH`.

| Bump | When | Example |
|---|---|---|
| MAJOR | Incompatible change (data migration that cannot be rolled back, removed feature) | `v2.0.0` |
| MINOR | New features, backwards compatible | `v1.1.0` |
| PATCH | Fixes only | `v1.0.1` |

Release steps:

```bash
git switch main && git pull
git merge --no-ff develop            # or merge the release pull request on GitHub
php artisan test --group=critical    # pre-release gate (full suite runs in CI)
git tag -a v1.0.0 -m "v1.0.0: first production release"
git push origin main v1.0.0
```

Release notes come straight from the Conventional Commits:
`git log --oneline v1.0.0..v1.1.0` (group by `feat` / `fix` / `perf`).

**The first release tag is `v1.0.0`**, to be created on `main` after this hardening work is merged. Tags
are created deliberately by a person, never automatically.

## How commits, tags and deployments relate

```
commits (feature/*, fix/*) ──PR──▶ develop ──release PR──▶ main ──tag──▶ v1.2.0 ──deploy──▶ production
                                                                           │
                                                           APP_VERSION=1.2.0 → image tags
```

- Production only ever runs a **tag**: `git checkout v1.2.0` on the server, `APP_VERSION=1.2.0`, build,
  `up -d` (see `docs/deployment.md` §5.3). Never deploy a branch head or an uncommitted change.
- The image tag equals the release version, so `docker images` shows what is deployed and rolling back is
  `APP_VERSION=<previous>` (see `docs/deployment.md` §7).
- A hotfix: `hotfix/*` from `main` → PR → `main` → tag `v1.2.1` → deploy → merge `main` back into `develop`.
- Back up the database before every deployment; migrations are forward-only.

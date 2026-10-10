# AGENTS.md — tds-ext-messages-pkg

Frontend extension for the customer↔owner message thread. It contributes a PHP
`Module` to `tds-core-frontend-api` and a nav entry, the `/messages` route and the
`messages-unread` widget to the panel products. Composed at build time.

Read `tds-frontend-contract-pkg/AGENTS.md` first (extension contract).
`tds-ext-support-tickets-pkg` is the reference full port; this extension has the same shape.

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit
```

## Hard rules

- Call the API with `apiFetch` from `tds-shared/api`, never a relative `fetch`.
- Send/edit outcomes are toasts; `error` state is for the load failure only. Never mount a `ToastHost`.
- A customer edits only their own messages in their company; anything else is a 404, never a 403.
- Every route change updates `php/docs/api.php` (a parity test enforces it).
- Migration class name and version prefix stay unique across all extensions.
- No CSS; shared `tds-shared` classes only.
- Versions move via the release workflow only; never bump by hand.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing routes, permissions, data model or islands |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island, page or widget markup |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.

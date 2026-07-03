# CLAUDE.md

This file provides guidance to AI coding agents working with code in this repository.

## What this is

`TDS-LP` is the working root for **Tracht Digital Solutions** — a freelance fullstack
business (Julian Tracht, Schwarzenbek near Hamburg). It is not one app: it is **ten
independent repos** checked out side by side, each with its own git history, CI, and
`AGENTS.md`. There is no root-level build, test, or package manifest — you always work
inside one repo at a time.

> `CLAUDE_CODE_PROMPT.md` at the root is the **original brief** (Next.js 16 / React /
> Vercel). It is historical and **no longer describes the system** — the project was
> re-architected into Astro frontends + PHP APIs. Trust the per-repo `AGENTS.md`,
> `README.md`, and `INSTALL.md` over that brief for anything technical.

## The ten repos

**Frontends — Astro 6 (`output: "static"`) + React islands + Tailwind v4:**

| Repo | Domain | Role |
|---|---|---|
| `tds-landingpage` | `tracht-digital.de` | Public marketing site (DE `/`, EN `/en/`) |
| `tds-blog` | `blog.tracht-digital.de` | Public blog, SSG; posts fetched at build time |
| `tds-admin` | `management.tracht-digital.de` | Internal admin panel (blog editor, time tracker) |
| `tds-customer` | `app.tracht-digital.de` | Customer portal (projects, invoices, docs, messages) |

**Backends — PHP 8.3 + Slim 4 + PDO + Phinx, served under `api.tracht-digital.de/*`:**

| Repo | Path | Owns |
|---|---|---|
| `tds-auth-api` | `/auth` (port 8003) | RS256 JWT issuance + JWKS; sessions |
| `tds-contact-api` | `/contact` (port 8002) | Contact form → Resend email + rate limit |
| `tds-content-api` | `/content` (port 8001) | `blog_post` table (blog CRUD) + `content_block` table (editable landingpage section content, `/landing`; also backs the blog's "Aktuelle Themen" via the `topics` key, `/topics`) |
| `tds-customer-api` | `/customer` (port 8004) | Customers, projects, invoices (Stripe), docs, time tracking |

**API gateway — PHP 8.3 + Slim 4 (transparent reverse proxy):**

| Repo | Domain | Role |
|---|---|---|
| `tds-api-gateway` | `api.tracht-digital.de` | Single public entry; routes by path prefix to the four backends above. Default `inprocess` (runs them inside one PHP-FPM app); `proxy` mode targets `/auth`→8003, `/contact`→8002, `/content`→8001, `/customer`→8004 |

**Shared library:**

- `tds-shared` — published to **GitHub Packages** as `@tracht-digital-solutions/tds-shared`.
  Design tokens/CSS, i18n strings (DE/EN), Zod schemas, shared TS types, and shared React
  islands (e.g. `ThemeToggle`). Consumed by the four frontends only.

`stuff/` holds raw brand assets (logos, portrait, AGB PDF) — not code.

## Big-picture architecture

- **`tds-api-gateway` is the single front door.** The first path segment selects the
  backend and the remainder is forwarded (`/auth/admin/login` → auth's `/admin/login`).
  auth/content/customer mount at root so the prefix is stripped; **contact-api keeps its own
  `/contact` route** (the frontend POSTs to `.../contact` with no sub-path), so the gateway
  rewrites `/contact`→contact's `/contact`. **By default (`GATEWAY_MODE=inprocess`) the
  gateway runs each backend *in-process* — it loads `services/<name>`'s Slim app and calls
  `Bootstrap::createApp(...)->handle()`, so the whole API surface is one PHP-FPM app with no
  service processes to start (the Plesk "install + start without SSH" model). The loopback
  `php -S` ports 8001–8004 + the cURL reverse-proxy only apply in the optional
  `GATEWAY_MODE=proxy` (supervisor/nginx/Docker run modes).** CORS
  stays owned by each upstream — don't add it at the gateway (it'd duplicate the header). CI
  assembles the gateway + all four services into a self-contained bundle on the orphan
  **`dev`** branch (auto, on a gateway push **or** a `repository_dispatch(api-pushed)` from
  any API repo, NOT deployed); the **`release`** branch + deploy is the manual Actions
  button. Alternatively the host can prefix-route with `deploy/nginx.conf.example`
  (zero PHP hop) instead of running the gateway process.
- **Auth is centralized in `tds-auth-api`.** It signs RS256 JWTs; every other backend
  verifies them via `/.well-known/jwks.json` and never sees the private key. Cookies are
  scoped `Domain=.tracht-digital.de` so one session works across `management.` / `app.`
  subdomains. **Phase-4 auth has shipped:** a unified `app_user` table + cookie login issues
  a per-admin RS256 JWT, and the content/customer admin endpoints verify it via JWKS
  (`admin=true` + RBAC permissions) — the old shared `ADMIN_TOKEN` Bearer is retired for
  those. `ADMIN_TOKEN` now only gates the gateway's `/wiki` route and is written by the
  installer as a shared secret; the first admin login is created at install time (the
  installer's `create_admin` step).
- **Frontends are fully static.** No SSR, no Node runtime on the production host. Auth
  gating happens via an inline `<script>` (admin: reads `localStorage` token; customer:
  relies on the httpOnly cookie + a per-page 401-redirect effect), never Astro server
  middleware. Never add `output: "server"`.
- **Content flows at build time, not runtime.** `tds-blog` and the landing page's Journal/
  Currently sections fetch posts from `tds-content-api` during `astro build` and bake the
  HTML. A failed fetch returns `[]` and the page falls back to static content — the build
  never breaks on an API hiccup. Do not fetch this content from the client at runtime.
  Two newer build-time content paths follow the same rule: (1) the **landingpage content
  editor** — most landingpage sections are editable via `tds-content-api`'s `/landing`
  content-block API, edited in `tds-admin`, pulled by `tds-landingpage`'s `src/lib/cms.ts`
  at build time (merged over the tds-shared/local default; a save fires a rebuild). (2)
  **blog English versions** — every post is reachable in DE (`/[slug]`) and EN
  (`/en/[slug]`); a post lacking a language is DeepL-translated at build time (graceful
  fallback when `DEEPL_API_KEY` is unset). Both bake static HTML — no runtime fetch.
- **`tds-shared` is the single source of truth for design + copy.** Brand tokens live in
  `styles/base.css` (`@theme` block), shared chrome in `styles/app.css`. To change a color,
  font, shared component style, or any editable copy string: edit it in `tds-shared`, bump
  the version, and republish — never duplicate into a frontend. Backends deliberately do
  **not** consume `tds-shared`; they hand-duplicate the small bit of Zod validation they
  need (keep the PHP validator and the Zod schema in sync when either changes).

## Commands

### Frontends (`tds-landingpage`, `tds-blog`, `tds-admin`, `tds-customer`)

```bash
npm install            # needs a GitHub PAT with read:packages — see below
npm run dev            # astro dev (landingpage: http://localhost:4321)
npm run build          # → dist/ (the static artifact that gets deployed)
npm run preview        # serve dist/ for inspection
npm run type-check     # astro check — must be 0 errors (this is the lint/typecheck gate)
npm run og:smoke       # landingpage + blog only: render OG card, catch font-path regressions
```

There is **no ESLint/test runner** in the frontends — `npm run type-check` (astro check)
is the correctness gate.

### `tds-shared`

```bash
npm run build          # tsup → dual ESM+CJS
npm run test           # vitest (watch);  npm run test:run for one-shot
npm run type-check     # tsc --noEmit
npm version patch && git push --follow-tags   # cut a release → CI publishes to GitHub Packages
```

### Backends (all four PHP APIs)

```bash
composer install
cp .env.example .env       # fill DB creds (+ Resend / Stripe / JWT keys per repo)
composer migrate           # phinx migrate -e local
composer start             # php -S localhost:<800x> -t public  (see port table above)
composer test              # phpunit
```

`tds-auth-api` also has `composer keygen` (run once per environment to create the RS256
keypair).

**Running a single PHP test:**

```bash
vendor/bin/phpunit --filter testMethodName
vendor/bin/phpunit tests/Path/To/SomeTest.php
```

**DB-backed integration tests skip unless `TDS_TEST_DB_DSN` (+ `_USER` / `_PASS`) is set.**
They run against **real MariaDB** on purpose (SQLite would mask `NOW()`, unique indexes,
transaction semantics) and drop/recreate the tables they touch. Spin a throwaway DB:

```bash
docker run --rm -d --name tds-maria \
  -e MARIADB_ROOT_PASSWORD=dev -e MARIADB_DATABASE=tds_content -p 3306:3306 mariadb:11
```

See each repo's `INSTALL.md` for the full recipe.

## GitHub Packages access (required for frontends)

`@tracht-digital-solutions/tds-shared` is on GitHub Packages, not npm. `npm install` fails
without a classic PAT carrying `read:packages` (and SSO-authorized for the
`Tracht-Digital-Solutions` org). Provide it via `~/.npmrc` or the `NPM_TOKEN` env var that
the repo `.npmrc` references. In CI, `secrets.NPM_TOKEN` is the PAT used both to install
from Packages and to push the `dev`/`release` branch — not `secrets.GITHUB_TOKEN`. A 401 = missing/
expired token; a 403 `read_package` despite a valid token = missing SSO authorization.

## Deployment (don't break the pipeline)

**Two-track branch model (replaced the old `build` branch).** Every repo has a **`dev`**
branch (developer version, **auto-built on every push to `main`**, NOT deployed) and a
**`release`** branch (production, **only on the manual Actions button**). The host pulls
**`release`**; the deploy webhook fires **only on a release run**. `ci.yml` is PR-only.
- **Frontends** (`dev.yml`/`release.yml`): build `dist/` → `dev` (Staging/Demo config,
  `PUBLIC_DEMO_MODE=true`) / `release` (real URLs, deploy ping).
- **Backends** (`dev.yml`/`release.yml` + `.github/assemble-bundle.sh`): bundle source +
  `vendor/` + phinx → `dev` / `release`; only release deploys + dispatches the gateway.
- **Gateway** (`dev.yml`/`release.yml` → reusable `_assemble.yml`): assemble gateway + 4
  services → `dev` (push / `api-pushed` dispatch) / `release` (manual).
- **tds-shared**: push → `@dev` prerelease; manual Release button → real version publish.

Host cutover: repoint each property's Git pull from `build` → **`release`** and press each
Release button once so `release` exists.

So: don't commit `vendor/` (the bundle installs it), and don't commit
`tds-auth-api/keys/private.pem` (gitignored; lives only in a password manager + the host
`.env`). The deploy token is carried inside `DEPLOY_WEBHOOK_URL` itself.

**Newer deploy secrets** (optional; no-op/fallback when unset): `LANDINGPAGE_REBUILD_*` on
`tds-content-api` (a `/landing` save triggers a landingpage rebuild) and `DEEPL_API_KEY` on
`tds-blog` (build-time fallback translation). Cross-repo **reusable GitHub workflows are
org-blocked** — deploy/dispatch shell stays inline per repo; the frontend
`npm install --no-package-lock` is required (Windows lockfile is win32-only).

## Cross-cutting conventions & gotchas

- **Tailwind v4 runs through `@tailwindcss/postcss`, never `@tailwindcss/vite`.** Astro 6
  ships Vite 7 with rolldown, and the Vite plugin trips on a missing `tsconfigPaths` field
  (withastro/astro#16542). Don't reintroduce the Vite plugin in any frontend.
- **lightningcss `cssTarget` lives in the shared `tdsViteBuild` preset.** All four
  frontends spread `tdsViteBuild` (from `@tracht-digital-solutions/tds-shared/astro`,
  since tds-shared 0.4.0; current 0.8.4) into `vite.build`. It pins the Safari floor so lightningcss
  keeps the `-webkit-backdrop-filter` prefix on the frosted `.brand-header`; without it
  the blur silently dies in Safari ≤17 — no error, no test. Don't hand-author the
  `cssTarget` array back into a frontend's `astro.config.mjs` (tds-shared#10).
- **Dark mode surface tokens.** All four frontends share a `data-theme="dark"` theme. The
  structural tokens (`--color-primary`, `--color-black`, `--color-paper`…) **flip** in dark
  mode. Any surface that must stay a fixed dark panel in both themes must use
  `--color-surface-navy` / `--color-surface-accent` / `--color-surface-ink`; elevated/glass
  surfaces use `--color-card`. Using a flipping token (or `bg-white`) as a fixed dark
  backdrop inverts/breaks in dark mode.
- **The `env()` precedence bug — present in all four APIs.** Never write
  `$_ENV[$key] ?? getenv($key) ?: $default`: PHP binds `??` tighter than `?:`, so a
  legitimately falsy value (`"0"`, `""`) gets clobbered by the default. Use explicit
  `?? false` checks. Every API repo was bitten by this via copy-paste; the gateway's
  `Bootstrap::env()` follows the same safe pattern (and all five now carry a comment
  documenting the trap).
- **i18n in Astro:** call `tFor(Astro.currentLocale)` in `.astro` files; pass `lang` as a
  prop into React islands. Never read `translations.de`/`.en` directly — that bypass is what
  made the language toggle a no-op.
- **Editable copy belongs in `tds-shared`**, not inlined in `.astro`. Short-lived inlining
  during prototyping is OK only with a `TODO: promote to tds-shared` comment.
- **OG image renderers (Satori + Resvg) are build-time only** — never import them from a
  runtime React island, and anchor the font dir to `process.cwd()`, not `import.meta.url`
  (Astro bundles the renderer into `dist/pages/og/` and an `import.meta.url`-relative path
  ENOENTs). `npm run og:smoke` is the cheap regression check.
- **Markdown rendering uses `set:html` without sanitization** — safe today only because the
  blog body is admin-authored and baked at build time. Add `isomorphic-dompurify` the day a
  non-admin can write a body or a client-side preview ships (see `tds-admin/AGENTS.md`).

## Where the detail lives

Each repo's **`AGENTS.md`** is the authoritative architecture/gotcha doc for that repo, and
its **`INSTALL.md`** has the full setup recipe. Per-repo work is tracked as **GitHub issues**
in the `Tracht-Digital-Solutions` org. Read the relevant repo's `AGENTS.md` before making
non-trivial changes there.

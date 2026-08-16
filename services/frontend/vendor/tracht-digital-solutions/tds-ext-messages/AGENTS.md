# Agent notes — tds-ext-messages

The customer↔owner message thread. Read `tds-frontend-contract-pkg`'s AGENTS.md
first (extension contract) and `tds-ext-support-tickets-pkg` as the reference
full port — this extension follows the same shape.

## Architecture

- **Backend** (`php/src/MessagesModule.php`, namespace `Tds\Ext\Messages`) — extends
  `AbstractModule`. Routes: `GET /messages` (thread, marks counterpart msgs read),
  `POST /messages`, `PATCH /messages/{id}`, `GET /messages/summary` (widget unread
  count). Auth is entirely the core `UserContext`: `messages:read`/`messages:write`
  (admins bypass), scoped by `activeCompanyId()`. `author_type` = `owner` for admin,
  else `customer`. Data via the core shared `PDO`; repository in `Domain/`.
- **Ownership on edit** — admins edit any message; a customer only their own
  `author_type='customer'` rows scoped to their company (`rowCount()==0` → 404, so
  ids aren't leakable). Ported verbatim from the legacy `Message\UpdateAction`.
- **Frontend** (`src/index.ts` manifest + `pages/Index.astro` + `islands/*`) — nav
  entry, `/messages` route (`MessageThread`), and the `messages-unread` widget.

## Gotchas

- **Call the API with `apiFetch` from `@tracht-digital-solutions/tds-shared/api`,
  never a relative `fetch`.** Every island used to define its own
  `const api = (path, init) => fetch(path, { credentials: "include", ...init })`
  with a RELATIVE path. In a product that resolves against the product's own
  static host (`management.`/`app.tracht-digital.de`), not the API — and the
  static host answers unknown paths with its SPA fallback, i.e. **200 + HTML**.
  So `res.ok` is `true`, `res.json()` throws, and the usual
  `.catch(() => setRows([]))` renders a calm, permanent empty state with no
  error and no console warning. `apiFetch` resolves the base from
  `<meta name="tds-api-base">` (written by the frontend host) and routes 401s
  through the host's confirm-against-`/me` backstop, which extension calls
  previously skipped entirely.
  The island tests match on the request PATH (`pathOf()`), which a relative
  fetch satisfies just as well — so one assertion per suite pins the **absolute
  host**. That is the line that fails if this ever regresses.


- **Send/edit failures are toasts (tds-shared `>=0.16.0`).** They used to write
  the same `error` state the LOAD failure uses, so a failed send replaced the
  thread-level banner and a successful one said nothing at all. `error` is the
  load failure only now. Never mount a `ToastHost` here — the frontend host
  owns the only one.

- **No customer/project FK** — those entities live in another domain (auth /
  customer management), so `customer_id`/`project_id` are loose unsigned refs;
  `customer_id` = the JWT active company id (nullable = admin all-company view).
- **Migration class name AND numeric prefix are extension-unique** (shared phinxlog
  across all composed extensions): `CreateMessagesMessage`, `20260722000001`.
- **Depends on the published contract** VCS-only (Composer `type:vcs`), npm `^1.0.0`
  from GitHub Packages — never a `path` repo.
- **Extension routes are Layout-wrapped by the host** (`panelHost({ layout })`); the
  page renders only its `<section>`, never a full `<html>`.

## Tests (frontend)

```bash
npm run test:run    # vitest, 80 tests (jsdom per-file via a @vitest-environment docblock)
```

- `islands/MessageThread.test.tsx` — the load-bearing assertion is
  **attribution**: every message is labelled "Julian" (owner) or "Sie"
  (customer) and carries an `author_type` class. Checking only that both labels
  EXIST passes when they are swapped — which would show a customer their own
  words as if the owner had written them — so each label is matched against its
  own message.
  A **403 is its own state** (no access); a **401 is deliberately NOT
  special-cased** and falls through to the generic error, because the host's
  pre-paint auth gate owns the logged-out case. Telling an expired user they
  "have no access" would point them at a permission they actually hold.
  The body renders as text, never HTML (asserted with an `<img onerror>`), and
  line breaks survive as separate paragraphs.
  Both the compose box and the edit box refuse an empty/whitespace body and
  keep their content when the request fails — a typed message is not
  recoverable once dropped.
- `islands/WidgetBody.test.tsx` — the unread tile. The `Number()` coercion is
  asserted with a zero-padded `"05"`, since `"5"` renders identically either way.
- `src/index.test.ts` + `tests/packaging.test.ts` — the manifest as a product
  build sees it, and that every specifier resolves, is exported, and ships.

Error-path tests deliberately answer with a POPULATED body and a non-OK status.
Against an EMPTY error body the ok-check is unobservable.

Verified by mutation: 41 deliberate breakages introduced, 41 caught.

## API-Referenz (`php/docs/api.php`)

This module implements the contract's optional `ApiDocSource`: `php/docs/api.php`
returns one entry per route (summary, params, responses, required permission),
and the admin frontend's API reference joins it onto the introspected Slim routes
by `"<METHOD> <pattern>"`. Two things to know before editing a route:

- **`pattern` must be the Slim pattern verbatim**, inline regex included
  (`/messages/{id:[0-9]+}`). A prettified path silently produces an orphan doc *and* an
  undocumented route rather than an error.
- **`php/tests/MessagesApiDocsTest.php` asserts both directions** — the documented
  set and the registered set must be the same set, every path placeholder must
  be described, and a named permission must exist in `permissions()`. Adding or
  renaming a route without touching `docs/api.php` fails there. That is the
  point: prose next to code rots, and a reference full of confident, wrong
  detail is worse than the bare route list it replaced.

# Testing

```bash
npm run test:run   # vitest; islands opt into jsdom per file via a @vitest-environment docblock
composer test      # phpunit
```

CI (`_build.yml`) runs both suites, plus type-check, `lint:primitives` and build, on
every run.

## Test environment against the current tds-shared

tds-shared is a **peer** dependency, so a fresh standalone install may resolve an old
version while every product build composes the current one. Keep these in mind:

- `apiFetch` first consults the host runtime config (`/tds-runtime.json`), so
  `fetch.mock.calls[0]` is that probe. Call **`primeRuntimeConfig(null)`** in
  `beforeEach`; panel products never ship that file, so "absent" matches production.
- `apiFetch` is **async**. `await waitFor(() => expect(fetch).toHaveBeenCalled())`
  before reading `mock.calls`.
- A multipart upload carries an **empty** `headers` object. Assert "no content-type
  header", never "headers is undefined".
- Error-path tests answer with a **populated** body and a non-OK status. Against an
  empty error body the ok-check is unobservable.
- At least one assertion per suite pins the **absolute API host** (see
  [frontend-conventions.md](frontend-conventions.md#api-calls-apifetch-never-a-relative-fetch)).

## Suites

### `islands/MessageThread.test.tsx`

- **Attribution is the load-bearing assertion.** Every message is labelled "Julian"
  (owner) or "Sie" (customer) and carries an `author_type` class. Each label is matched
  against its own message; checking only that both labels exist passes when they are
  swapped, which would show customers their own words as the owner's.
- **403 is its own state** (no access). **401 is deliberately not special-cased**: the
  host's pre-paint auth gate owns the logged-out case, and "no access" would point an
  expired user at a permission they actually hold.
- The body renders as text, never HTML (asserted with an `<img onerror>`); line breaks
  survive as separate paragraphs.
- Compose and edit boxes refuse an empty/whitespace body and **keep their content when
  the request fails**. A typed message can't be recovered once dropped.

### Other suites

- `islands/WidgetBody.test.tsx` — the unread tile. `Number()` coercion is asserted with a
  zero-padded `"05"`, since `"5"` renders identically either way.
- `src/index.test.ts` + `tests/packaging.test.ts` — the manifest as a product build sees
  it; every specifier resolves, is exported and ships.
- `php/tests/MessagesModuleTest.php`, `php/tests/MessagesApiDocsTest.php` — module and
  API-doc parity.

Mutation check: 41 deliberate breakages, 41 caught.

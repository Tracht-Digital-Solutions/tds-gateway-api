# INSTALL — Alles per Docker mit einem Befehl

Das mitgelieferte `Dockerfile` packt das Gateway **und** die Backends in ein
einziges Image; `docker-compose.yml` ergänzt MariaDB. Ein Befehl bringt die
komplette API-Plattform hoch.

## Voraussetzungen

- Docker mit BuildKit (Docker 23+ / `docker compose` v2 — Standard heute).
- Die Backend-Repos **und** die Extension-Pakete des `frontend`-Service **neben**
  diesem Repo ausgecheckt:

  ```
  TDS-LP/
    tds-gateway-api/          ← hier
    tds-auth-api/
    tds-customer-api/
    tds-core-frontend-api/    ← frontend (Default-Route)
    tds-frontend-contract-pkg/
    tds-ext-time-tracker-pkg/  tds-ext-customers-pkg/  tds-ext-billing-pkg/
    tds-ext-lexware-pkg/       tds-ext-tools-pkg/      tds-ext-messages-pkg/
    tds-ext-projects-pkg/      tds-ext-documents-pkg/
  ```

  Sie werden als *named build contexts* ins Image gezogen — kein GitHub-Token,
  kein vorheriges `composer install` nötig. Der `frontend`-Service komponiert
  seine Extensions über Composer-`path`-Repos; das Image kopiert sie
  (`COMPOSER_MIRROR_PATH_REPOS=1`) ins `vendor/`, statt zu symlinken.

## Schnellstart

```bash
cd tds-gateway-api
cp .env.docker.example .env.docker     # optional, aber empfohlen
docker compose up --build
```

Das war's. Beim ersten Start:

1. MariaDB startet und legt via `deploy/db-init/01-databases.sql` die drei
   Datenbanken an (`tds_auth`, `tds_customer`, `tds_frontend`).
2. Das API-Image baut alle PHP-Projekte (Multi-Stage, Prod-Deps; `frontend`
   inkl. seiner komponierten Extensions).
3. Der Entrypoint schreibt für jeden Service eine `services/<name>/.env`
   (aus den Compose-Variablen, mit dev-tauglichen Defaults), erzeugt bei
   Bedarf ein RS256-Dev-Keypair für `auth` und wartet auf die DB. `auth` +
   `customer` migrieren beim ersten Request; `frontend` migriert seine
   Extensions über seinen eigenen In-Process-Migrator.
4. Supervisor startet die Prozesse: Gateway `:8000` + Services (auth :8003,
   customer :8004, frontend :8100) auf dem Loopback.

Erreichbar:

```bash
curl http://localhost:8080/             # Gateway-Navigation
curl http://localhost:8080/healthz      # aggregierte Health aller Services
curl http://localhost:8080/tools/catalog  # → frontend (Default-Route)
```

(Der Host-Port ist `API_PORT`, Default `8080`; im Container lauscht das
Gateway immer auf `8000`.)

## Konfiguration / Secrets

Alle echten Secrets gehören in `.env.docker` (Vorlage:
`.env.docker.example`). Relevante Werte:

| Variable | Zweck |
|---|---|
| `DB_USER` / `DB_PASS` / `DB_ROOT_PASS` | MariaDB-Zugang |
| `API_PORT` | Host-Port des Gateways (Default 8080) |
| `ADMIN_TOKEN` | gemeinsamer Admin-Bearer (auth/customer) |
| `JWT_PRIVATE_KEY` | leer lassen → Container erzeugt Dev-Keypair; für Prod echten Key setzen |
| `SETTINGS_ENCRYPTION_KEY` | verschlüsselt die zur Laufzeit gesetzten Dienst-Secrets (customer/frontend) |
| `MAIL_DSN` | optionaler SMTP-DSN für den frontend-Mailer (leer → NullMailer) |
| `DOCUMENT_SIGN_SECRET` | signierte Dokument-Links (customer + documents-Extension) |

Drittanbieter-Keys (Stripe, DeepL, Lexware, GitHub-Rebuild) werden **nicht**
hier gesetzt, sondern zur Laufzeit im Admin-Frontend („Einstellungen“).

Eine bereits vorhandene `services/<name>/.env` (z. B. per Volume gemountet)
gewinnt immer — der Entrypoint überschreibt sie nicht.

## Statische Frontends (optional)

Die Frontends sind statisch und werden normalerweise eigenständig deployt. Wer
sie lokal mitlaufen lassen will, baut zuerst je ein `dist/` und startet dann das
`frontends`-Profil:

```bash
for d in tds-landingpage-frontend tds-blog-frontend tds-admin-frontend tds-customer-frontend; do
  (cd "../$d" && npm install && npm run build)   # braucht NPM_TOKEN für tds-shared-pkg
done
docker compose --profile frontends up --build
```

Dann: Landingpage `:4321`, Blog `:4322`, Admin `:4323`, Portal `:4324`
(Ports via `LANDING_PORT` … überschreibbar). Ein schlankes nginx liefert die
gemounteten `dist/`-Ordner aus.

## Betrieb

```bash
docker compose logs -f api      # Logs aller Prozesse (stdout/stderr)
docker compose down             # stoppen (DB-Volume bleibt erhalten)
docker compose down -v          # inkl. DB-Volume löschen (Reset)
docker compose up --build -d    # nach Code-Änderungen neu bauen + im Hintergrund
```

Migrationen laufen bei jedem Containerstart erneut (idempotent). Den Code
aktualisierst du mit `docker compose up --build` (zieht den aktuellen Stand
der `../tds-*`-Checkouts).

## Production-Hinweis

Das Image eignet sich auch für Single-Host-Prod: echte Secrets in
`.env.docker`, einen echten `JWT_PRIVATE_KEY` setzen, einen Reverse-Proxy
(TLS) vor `API_PORT` hängen und die MariaDB-Daten über das `db-data`-Volume
sichern. Für den klassischen Plesk-Weg (ohne Docker) siehe
[`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md).

# INSTALL — Alles per Docker mit einem Befehl

Das mitgelieferte `Dockerfile` packt das Gateway **und** die vier
Micro-Backends in ein einziges Image; `docker-compose.yml` ergänzt MariaDB.
Ein Befehl bringt die komplette API-Plattform hoch.

## Voraussetzungen

- Docker mit BuildKit (Docker 23+ / `docker compose` v2 — Standard heute).
- Die vier API-Repos **neben** diesem Repo ausgecheckt:

  ```
  TDS-LP/
    tds-api-gateway/   ← hier
    tds-auth-api/
    tds-contact-api/
    tds-content-api/
    tds-customer-api/
  ```

  Sie werden als *named build contexts* (`../tds-*-api`) ins Image gezogen —
  kein GitHub-Token, kein vorheriges `composer install` nötig.

## Schnellstart

```bash
cd tds-api-gateway
cp .env.docker.example .env.docker     # optional, aber empfohlen
docker compose up --build
```

Das war's. Beim ersten Start:

1. MariaDB startet und legt via `deploy/db-init/01-databases.sql` die vier
   Datenbanken an.
2. Das API-Image baut alle fünf PHP-Projekte (Multi-Stage, Prod-Deps).
3. Der Entrypoint schreibt für jeden Service eine `services/<name>/.env`
   (aus den Compose-Variablen, mit dev-tauglichen Defaults), erzeugt bei
   Bedarf ein RS256-Dev-Keypair für `auth`, wartet auf die DB und migriert
   alle vier Services.
4. Supervisor startet die fünf Prozesse: Gateway `:8000` + vier Services auf
   dem Loopback.

Erreichbar:

```bash
curl http://localhost:8080/            # Gateway-Navigation
curl http://localhost:8080/healthz     # aggregierte Health aller Services
curl http://localhost:8080/content/blog
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
| `ADMIN_TOKEN` | gemeinsamer Admin-Bearer (auth/content/customer) |
| `JWT_PRIVATE_KEY` | leer lassen → Container erzeugt Dev-Keypair; für Prod echten Key setzen |
| `RESEND_API_KEY` | Kontaktformular-Mailversand |
| `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` | Rechnungen |
| `DOCUMENT_SIGN_SECRET` | signierte Dokument-Links (customer) |

Eine bereits vorhandene `services/<name>/.env` (z. B. per Volume gemountet)
gewinnt immer — der Entrypoint überschreibt sie nicht.

## Statische Frontends (optional)

Die vier Frontends sind statisch und werden normalerweise eigenständig
deployt. Wer sie lokal mitlaufen lassen will, baut zuerst je ein `dist/` und
startet dann das `frontends`-Profil:

```bash
for d in tds-landingpage tds-blog tds-admin tds-customer; do
  (cd "../$d" && npm install && npm run build)   # braucht NPM_TOKEN für tds-shared
done
docker compose --profile frontends up --build
```

Dann: Landingpage `:4321`, Blog `:4322`, Admin `:4323`, Portal `:4324`
(Ports via `LANDING_PORT` … überschreibbar). Ein schlankes nginx liefert die
gemounteten `dist/`-Ordner aus.

## Betrieb

```bash
docker compose logs -f api      # Logs aller fünf Prozesse (stdout/stderr)
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

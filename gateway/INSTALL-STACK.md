# INSTALL — Gateway + die Backends zusammen betreiben

Diese Anleitung beschreibt, wie aus einem **manuell deployten Gateway-Bundle**
die Backends (`auth`, `customer`, `frontend`) mitlaufen. `frontend` ist
`tds-core-frontend-api` — die komponierte Basis + Extensions, die die
archivierten `content`/`contact`-Backends ersetzt hat, und die Default-Route des
Gateways. Sobald du das Bundle ausgecheckt und einmal eingerichtet hast, startet
`bin/start-stack.sh` alle Service-Prozesse hinter dem Gateway.

> Für die Container-Variante (ein Befehl, alles läuft) siehe
> [`INSTALL-DOCKER.md`](./INSTALL-DOCKER.md). Für Plesk siehe
> [`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md).

## Was im Bundle steckt

Der `release`-Branch dieses Repos wird von CI assembliert und enthält alles,
inklusive aller `vendor/`-Verzeichnisse (auch Phinx für Migrationen). Der alte
`build`-Branch existiert nicht mehr; `dev` ist die nicht-deployte Developer-
Version, `release` (manueller Knopf) ist das, was der Host zieht:

```
gateway/                ← Slim-Proxy (Docroot: gateway/public, Port 8000)
gateway/bin/start-stack.sh
gateway/bin/migrate-stack.sh
services/auth/          ← tds-auth-api          (127.0.0.1:8003)
services/customer/      ← tds-customer-api      (127.0.0.1:8004)
services/frontend/      ← tds-core-frontend-api (127.0.0.1:8100, Default-Route)
Procfile, services.json, BUILD_INFO.json
```

Beim `release`-Branch läuft auf dem Host **kein** `composer install` — die
`vendor/`-Verzeichnisse sind im Bundle. (Hat der Host einen PHP-Composer, kann
man alternativ `main` auschecken und `composer install --no-dev` pro
`services/<name>/` + Gateway selbst ausführen.)

## 1. Bundle holen

```bash
git clone -b release https://github.com/Tracht-Digital-Solutions/tds-gateway-api.git /srv/tds
cd /srv/tds
```

Spätere Updates: `git pull` (oder der Deploy-Webhook, s. u.). Den
`release`-Branch gibt es erst nach dem ersten manuellen Release-Build
(*Actions → Release → Run workflow*).

> **Schnellweg — Web-Installer:** Statt der Schritte 2–4 von Hand kann der
> Assistent unter **`https://api.tracht-digital.de/install.php`** alles
> erledigen: Datenbank verbinden/anlegen, alle `.env`-Dateien schreiben, das
> Auth-Schlüsselpaar erzeugen und die Migrationen ausführen. Danach
> `gateway/public/install.php` **löschen** (während der Einrichtung idealerweise
> per IP beschränken). Die manuellen Schritte unten bleiben als Referenz.

## 2. Pro Service eine `.env` anlegen

Jeder Service liest seine DB-Zugangsdaten und Secrets aus
`services/<name>/.env`. Vorlage ist jeweils `services/<name>/.env.example`:

```bash
for name in auth customer frontend; do
  cp "services/$name/.env.example" "services/$name/.env"
  $EDITOR "services/$name/.env"     # DB-Creds + Secrets eintragen
done
```

Wichtig je Service (Details im `INSTALL.md`/`README.md` des jeweiligen Repos):

- **auth** — DB; `ADMIN_TOKEN`; JWT. Entweder `JWT_PRIVATE_KEY` in die `.env`
  oder `services/auth/keys/private.pem` ablegen (Rechte `600`).
- **customer** — DB; `ADMIN_TOKEN`; `SETTINGS_ENCRYPTION_KEY`;
  `DOCUMENT_SIGN_SECRET`.
- **frontend** (`tds-core-frontend-api`) — DB (`tds_frontend`, alle Extensions
  teilen sie); `AUTH_API_URL`; `SETTINGS_ENCRYPTION_KEY`; optional `MAIL_DSN`;
  `DOCUMENT_ROOT_DIR`/`DOCUMENT_SIGN_SECRET` (documents-Extension).

Drittanbieter-Keys (Stripe, DeepL, Lexware, GitHub-Rebuild) kommen **nicht** in
die `.env`, sondern zur Laufzeit ins Admin-Frontend („Einstellungen“,
verschlüsselt in `app_setting`).

Das **Gateway** braucht nur dann eine `.env`, wenn die Service-Ports von den
`*_UPSTREAM`-Defaults abweichen (`auth` :8003, `customer` :8004, `frontend`
:8100) oder `GATEWAY_DEFAULT_SERVICE`/`GATEWAY_SERVICES` überschrieben werden
sollen. Eigene Secrets hat das Gateway keine.

## 3. Datenbanken + Migrationen

Pro Service eine MariaDB-Datenbank + User anlegen (Namen müssen zur jeweiligen
`.env` passen — Defaults: `tds_auth`, `tds_customer`, `tds_frontend`). Dann aus
dem Bundle migrieren:

```bash
bin/migrate-stack.sh          # phinx migrate -e production für auth + customer
```

`frontend` wird **nicht** von `migrate-stack.sh` migriert: `tds-core-frontend-api`
hat keine einzelne `db/migrations` + `phinx.php`, sondern komponiert die
Migrationen aller aktivierten Extensions und wendet sie über seinen **eigenen**
In-Process-Migrator beim ersten Request an (`AUTO_MIGRATE=1`; der Web-Installer
stößt ihn zusätzlich einmal an).

## 4. Services starten

> **Einfacher Weg auf einem PHP-FPM-Host:** Im Standardmodus
> `GATEWAY_MODE=inprocess` bedient das Gateway alle Backends im eigenen
> FPM-Prozess — dann ist dieser Abschnitt **überflüssig** (nichts zu starten),
> es genügt Abschnitt 5 (Docroot auf `gateway/public`). Die folgenden
> Service-Prozesse braucht nur der **Proxy-Modus** (`GATEWAY_MODE=proxy`), z. B.
> hinter nginx ohne FPM.

`bin/start-stack.sh` startet die Service-Prozesse **idempotent** (ein schon
laufender Service wird nicht doppelt gestartet):

```bash
# Standard: die Services starten; das Gateway liefert der Webserver aus.
bin/start-stack.sh

# Falls du auch das Gateway als eigenen PHP-Prozess auf :8000 willst:
START_GATEWAY=1 bin/start-stack.sh
```

Knöpfe (per Env):

| Variable | Default | Zweck |
|---|---|---|
| `PHP_BIN` | `php` | PHP-Binary (Plesk: `/opt/plesk/php/8.3/bin/php`) |
| `START_GATEWAY` | `0` | `1` = Gateway-Prozess auf `:8000` mitstarten |
| `RUN_MIGRATIONS` | `0` | `1` = vor dem Start je Service migrieren |
| `TDS_SERVICES_DIR` | `<bundle>/services` | abweichender Service-Pfad |
| `TDS_LOG_DIR` | `<bundle>/logs` | Log-Verzeichnis |

### Dauerhaft halten

**Mit Root (empfohlen):** `deploy/supervisor.conf.example` übernehmen, die
Pfade anpassen, `supervisorctl reread && supervisorctl update`.

**Ohne Root:** `bin/start-stack.sh` als Cron-Job eintragen — einmal `@reboot`
und einmal alle 5 Minuten (Watchdog; das Skript ist idempotent und startet nur
fehlende Prozesse):

```cron
@reboot     /srv/tds/gateway/bin/start-stack.sh
*/5 * * * * /srv/tds/gateway/bin/start-stack.sh
```

## 5. Webserver auf das Gateway zeigen

Drei Modelle (eines wählen):

1. **Gateway unter PHP-FPM** (Plesk-Stil): Docroot auf `gateway/public`, nur
   die Service-Prozesse laufen (kein `START_GATEWAY`). Das mitgelieferte
   `gateway/public/.htaccess` macht das Front-Controller-Rewrite.
2. **Gateway als Prozess:** `START_GATEWAY=1`, Webserver/Reverse-Proxy zeigt
   auf `127.0.0.1:8000`.
3. **Nur nginx (kein PHP-Hop):** `deploy/nginx.conf.example` routet `/auth` +
   `/customer` zu ihren Ports und alles andere zu `:8100` (frontend); das
   Gateway selbst läuft dann nicht.

## 6. Prüfen

```bash
curl http://localhost:8000/              # Prefix-Navigation (Gateway lebt)
curl http://localhost:8000/healthz       # aggregierte Health aller Services
curl http://localhost:8000/tools/catalog # → frontend (Default-Route)
```

`/healthz` meldet `503`, sobald ein Service nicht erreichbar ist — der
fehlende `php -S`-Prozess steht dann im Watchdog-Log.

## 7. Updates / Deploy-Webhook

CI baut den `dev`-Branch nach jedem `main`-Push (Gateway **oder** ein Backend
per `api-pushed`-Dispatch) automatisch — ohne Deploy. Der `release`-Branch wird
**nur per manuellem Actions-Knopf** gebaut und pingt dann `DEPLOY_WEBHOOK_URL`.
Der Host zieht `release` und muss darauf `git pull` + `bin/migrate-stack.sh` +
Service-Neustart ausführen, z. B.:

```bash
cd /srv/tds && git pull --ff-only
bin/migrate-stack.sh
pkill -f 'php -S 127.0.0.1:81' -f 'php -S 127.0.0.1:800' || true
bin/start-stack.sh
```

(Bei Supervisor stattdessen `supervisorctl restart tds:*`.)

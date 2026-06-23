# INSTALL — Gateway + die vier APIs zusammen betreiben

Diese Anleitung beschreibt, wie aus einem **manuell deployten Gateway-Bundle**
die vier Micro-Backends (`auth`, `contact`, `content`, `customer`) mitlaufen.
Sobald du das Bundle ausgecheckt und einmal eingerichtet hast, startet
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
services/auth/          ← tds-auth-api     (127.0.0.1:8003)
services/contact/       ← tds-contact-api  (127.0.0.1:8002)
services/content/       ← tds-content-api  (127.0.0.1:8001)
services/customer/      ← tds-customer-api (127.0.0.1:8004)
Procfile, services.json, BUILD_INFO.json
```

Beim `release`-Branch läuft auf dem Host **kein** `composer install` — die
`vendor/`-Verzeichnisse sind im Bundle. (Hat der Host einen PHP-Composer, kann
man alternativ `main` auschecken und `composer install --no-dev` pro
`services/<name>/` + Gateway selbst ausführen.)

## 1. Bundle holen

```bash
git clone -b release https://github.com/Tracht-Digital-Solutions/tds-api-gateway.git /srv/tds
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
for name in auth contact content customer; do
  cp "services/$name/.env.example" "services/$name/.env"
  $EDITOR "services/$name/.env"     # DB-Creds + Secrets eintragen
done
```

Wichtig je Service (Details im `INSTALL.md` des jeweiligen API-Repos):

- **auth** — DB; `ADMIN_TOKEN`; JWT. Entweder `JWT_PRIVATE_KEY` in die `.env`
  oder `services/auth/keys/private.pem` ablegen (Rechte `600`).
- **contact** — DB (Rate-Limit); `RESEND_API_KEY`.
- **content** — DB; `ADMIN_TOKEN` (muss zu auth/customer passen).
- **customer** — DB; `ADMIN_TOKEN`; Stripe-Keys; `DOCUMENT_SIGN_SECRET`.

Das **Gateway** braucht nur dann eine `.env`, wenn die Service-Ports von den
`*_UPSTREAM`-Defaults (`127.0.0.1:800x`) abweichen — oder wenn das interne
`/wiki` aktiv sein soll: dann `ADMIN_TOKEN` setzen (gleicher Admin-Token wie
die Backends; ohne ihn ist `/wiki` deaktiviert, 404).

## 3. Datenbanken + Migrationen

Pro Service eine MariaDB-Datenbank + User anlegen (Namen müssen zur jeweiligen
`.env` passen — Defaults: `tds_auth`, `tds_contact_ratelimit`, `tds_content`,
`tds_customer`). Dann aus dem Bundle migrieren:

```bash
bin/migrate-stack.sh          # ruft phinx migrate -e production für alle vier auf
```

## 4. Services starten

`bin/start-stack.sh` startet die vier Service-Prozesse **idempotent** (ein
schon laufender Service wird nicht doppelt gestartet):

```bash
# Standard: die vier Services starten; das Gateway liefert der Webserver aus.
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
   die vier Service-Prozesse laufen (kein `START_GATEWAY`). Das mitgelieferte
   `gateway/public/.htaccess` macht das Front-Controller-Rewrite.
2. **Gateway als Prozess:** `START_GATEWAY=1`, Webserver/Reverse-Proxy zeigt
   auf `127.0.0.1:8000`.
3. **Nur nginx (kein PHP-Hop):** `deploy/nginx.conf.example` routet direkt zu
   den vier Ports; das Gateway selbst läuft dann nicht.

## 6. Prüfen

```bash
curl http://localhost:8000/             # Prefix-Navigation (Gateway lebt)
curl http://localhost:8000/healthz      # aggregierte Health aller vier Services
curl http://localhost:8000/content/blog # → content-api
```

`/healthz` meldet `503`, sobald ein Service nicht erreichbar ist — der
fehlende `php -S`-Prozess steht dann im Watchdog-Log.

## 7. Updates / Deploy-Webhook

CI baut den `dev`-Branch nach jedem `main`-Push (Gateway **oder** eine der vier
APIs, per `api-pushed`-Dispatch) automatisch — ohne Deploy. Der `release`-Branch
wird **nur per manuellem Actions-Knopf** gebaut und pingt dann
`DEPLOY_WEBHOOK_URL`. Der Host zieht `release` und muss darauf
`git pull` + `bin/migrate-stack.sh` + Service-Neustart ausführen, z. B.:

```bash
cd /srv/tds && git pull --ff-only
bin/migrate-stack.sh
pkill -f 'php -S 127.0.0.1:800' || true
bin/start-stack.sh
```

(Bei Supervisor stattdessen `supervisorctl restart tds:*`.)

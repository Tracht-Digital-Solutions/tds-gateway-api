# Release auf Plesk — Domains, Subdomains, ein API-Projekt

Diese Anleitung beschreibt das komplette Produktions-Release der TDS-Plattform auf
einem Plesk-Host (Obsidian, mit Git-Extension, PHP 8.3 FPM, MariaDB, Let's Encrypt).
Sie deckt **alle fünf Web-Properties** ab: vier statische Frontends und das
API-Bundle, das Gateway + alle vier PHP-Services als **ein Plesk-Projekt**
(eine Subdomain, ein Git-Checkout) deployt.

Alle Repos veröffentlichen ihr deploybares Artefakt auf einem orphan
**`build`-Branch** (CI force-pusht nach jedem grünen `main`-Push). Plesk zieht
immer nur diesen Branch — nie `main`.

| (Sub)Domain | Repo | Branch | Art |
|---|---|---|---|
| `tracht-digital.de` | `tds-landingpage` | `build` | statisch (HTML/CSS/JS) |
| `blog.tracht-digital.de` | `tds-blog` | `build` | statisch |
| `admin.tracht-digital.de` | `tds-admin` | `build` | statisch |
| `app.tracht-digital.de` | `tds-customer` | `build` | statisch |
| `api.tracht-digital.de` | `tds-api-gateway` | `build` | PHP-Bundle: Gateway + `services/{auth,contact,content,customer}` |

---

## 1. DNS + Subdomains + SSL

1. **DNS**: `A`-Record für `tracht-digital.de` auf die Server-IP, dazu je ein
   `A`-Record (oder `CNAME` auf die Hauptdomain) für `blog`, `admin`, `app`, `api`
   (+ optional `www`).
2. **Plesk**: Hauptdomain `tracht-digital.de` als Abonnement anlegen, dann unter
   *Websites & Domains → Subdomain hinzufügen* die vier Subdomains erstellen.
   Jede Subdomain bekommt ihr eigenes Docroot (z. B. `blog.tracht-digital.de/httpdocs`).
3. **SSL**: Let's-Encrypt-Zertifikat je (Sub)Domain ausstellen (oder ein
   Wildcard-Zertifikat `*.tracht-digital.de` via DNS-Challenge) und
   *„Von HTTP zu HTTPS umleiten"* aktivieren. Wichtig: Cookies werden mit
   `Domain=.tracht-digital.de` gesetzt — `admin.`, `app.` und `api.` müssen
   deshalb zwingend unter derselben registrierbaren Domain laufen (tun sie hier).

## 2. GitHub-Zugriff für die Plesk-Git-Extension

Die Repos sind privat. Je Domain in der Git-Extension das Repo per **SSH-URL**
einbinden (`git@github.com:Tracht-Digital-Solutions/<repo>.git`); den von Plesk
angezeigten öffentlichen Schlüssel im jeweiligen GitHub-Repo unter
*Settings → Deploy keys* (read-only) hinterlegen.

## 3. Die vier statischen Frontends

Für jede der vier Frontend-(Sub)Domains identisch:

1. *Git → Repository hinzufügen*: Repo-URL, **Branch `build`**, Deploy-Modus
   „automatisch", Zielpfad = Docroot der (Sub)Domain.
2. *Hosting-Einstellungen*: **PHP deaktivieren** (rein statische Auslieferung),
   bevorzugt „nginx direkt ausliefern" für statische Dateien.
3. **Deploy-Webhook verdrahten**: Die Git-Extension zeigt eine
   *Webhook-URL* („Repository aktualisieren") an. Diese URL als Secret
   `DEPLOY_WEBHOOK_URL` im **jeweiligen Frontend-Repo** auf GitHub hinterlegen.
   Die CI pingt sie per GET nach jedem `build`-Push — Plesk pullt dann und die
   Seite ist live. (Der Token steckt in der URL selbst; nirgendwo sonst ablegen.)
4. `admin.tracht-digital.de` ist `noindex` und per Token-Gate geschützt; wer
   zusätzlich abdichten will, legt in Plesk eine IP-Beschränkung oder BasicAuth
   davor (optional).

## 4. `api.tracht-digital.de` — das API-Bundle als ein Projekt

Der `build`-Branch dieses Repos enthält das von CI assemblierte Bundle:

```
gateway/            ← Slim-Proxy (Docroot zeigt auf gateway/public)
services/auth/      ← tds-auth-api      (Port 8003)
services/contact/   ← tds-contact-api   (Port 8002)
services/content/   ← tds-content-api   (Port 8001)
services/customer/  ← tds-customer-api  (Port 8004)
Procfile, services.json, BUILD_INFO.json
```

Alle `vendor/`-Verzeichnisse sind enthalten (inkl. Phinx für Migrationen) —
**auf dem Host läuft kein `composer install`.**

### 4.1 Git + Docroot

1. *Git → Repository hinzufügen* auf der Subdomain `api.`: dieses Repo,
   **Branch `build`**, Zielpfad = Docroot-Verzeichnis der Subdomain
   (z. B. `api.tracht-digital.de`-Ordner).
2. *Hosting-Einstellungen*: **Docroot auf `<zielpfad>/gateway/public`** stellen,
   PHP 8.3 (FPM) aktivieren.

Damit läuft das Gateway als gewöhnliche PHP-FPM-App — das mitgelieferte
`gateway/public/.htaccess` macht das Front-Controller-Rewrite unter Apache.
Der `gateway:`-Eintrag im `Procfile` (eigener Prozess auf `:8000`) wird in
diesem Modell **nicht** benötigt.

### 4.2 Die vier Service-Prozesse

Die Services laufen als lokale PHP-Server, erreichbar nur via `127.0.0.1`
(die Ports müssen zu den `*_UPSTREAM`-Defaults des Gateways passen):

```
auth     → 127.0.0.1:8003
contact  → 127.0.0.1:8002
content  → 127.0.0.1:8001
customer → 127.0.0.1:8004
```

**Mit Root-Zugang (empfohlen):** `deploy/supervisor.conf.example` übernehmen,
die fünf `program:`-Pfade auf den Plesk-Zielpfad anpassen (den
`tds-gateway`-Block weglassen, s. o.) und `supervisorctl reread && update`.

**Ohne Root:** Wrapper-Skript außerhalb des Git-Zielpfads ablegen
(z. B. `/var/www/vhosts/tracht-digital.de/bin/start-tds-services.sh`):

```sh
#!/bin/sh
# Startet fehlende TDS-Service-Prozesse; idempotent (Watchdog-tauglich).
PHP=/opt/plesk/php/8.3/bin/php
BUNDLE=/var/www/vhosts/tracht-digital.de/api.tracht-digital.de
LOGS=/var/www/vhosts/tracht-digital.de/logs

for svc in auth:8003 contact:8002 content:8001 customer:8004; do
  name=${svc%%:*}; port=${svc##*:}
  pgrep -f "php -S 127.0.0.1:$port" >/dev/null && continue
  nohup "$PHP" -S "127.0.0.1:$port" -t "$BUNDLE/services/$name/public" \
    >> "$LOGS/tds-$name.log" 2>&1 &
done
```

In Plesk unter *Geplante Aufgaben* zweimal eintragen: einmal `@reboot`, einmal
alle 5 Minuten (Watchdog — startet abgestürzte Prozesse neu).

### 4.3 `.env` + Schlüssel je Service

Jeder Service braucht seine eigene `.env` **im Service-Ordner des Checkouts**
(`services/<name>/.env`, Vorlage: `services/<name>/.env.example`). `.env`-Dateien
sind nicht im Repo getrackt und überleben Deploys; trotzdem gehören alle Werte
zusätzlich in den Passwort-Manager.

- **auth**: DB-Zugang, JWT-Konfiguration. Zusätzlich `keys/private.pem` aus dem
  Passwort-Manager nach `services/auth/keys/` kopieren (Dateirechte `600`).
  `keys/public.pem` liegt bereits im Bundle.
- **contact**: DB-Zugang (Rate-Limit-DB), Resend-API-Key.
- **content**: DB-Zugang, `ADMIN_TOKEN`.
- **customer**: DB-Zugang, Stripe-Keys, `ADMIN_TOKEN`, JWKS-URL.
- **gateway**: braucht standardmäßig **keine** `.env` — die Upstream-Defaults
  (`127.0.0.1:800x`) passen zu 4.2. Nur für das interne `/wiki` zusätzlich
  `ADMIN_TOKEN` setzen (gleicher Admin-Token; ohne ihn ist `/wiki` 404).

Details je Service: das `INSTALL.md` im jeweiligen API-Repo.

### 4.4 Datenbanken + Migrationen

1. In Plesk je Service eine MariaDB-Datenbank + eigenen DB-User anlegen
   (`tds_auth`, `tds_contact_ratelimit`, `tds_content`, `tds_customer` — Namen
   frei, müssen nur zur jeweiligen `.env` passen).
2. Migrationen laufen **aus dem Bundle** (Phinx liegt im `vendor/` jedes
   Service; `production` ist das Default-Environment der `phinx.php`):

```sh
cd <zielpfad>
for name in auth contact content customer; do
  (cd "services/$name" && /opt/plesk/php/8.3/bin/php vendor/bin/phinx migrate -e production)
done
```

### 4.5 Deploy-Aktionen + Webhook

In der Git-Extension der `api.`-Subdomain unter *„Bereitstellungsaktionen"*
hinterlegen (läuft nach jedem Pull):

```sh
for name in auth contact content customer; do
  (cd "services/$name" && /opt/plesk/php/8.3/bin/php vendor/bin/phinx migrate -e production)
done
# Service-Prozesse neu starten, damit der frische Code greift:
pkill -f 'php -S 127.0.0.1:800' || true
/var/www/vhosts/tracht-digital.de/bin/start-tds-services.sh
```

Die *Webhook-URL* dieser Git-Instanz als Secret `DEPLOY_WEBHOOK_URL` im
**tds-api-gateway-Repo** hinterlegen — und **nur dort**. Die vier API-Repos
brauchen das Secret nicht: ein Push in ein API-Repo feuert per
`repository_dispatch` den Assemble-Workflow des Gateways, der das Bundle neu
baut und selbst den Webhook pingt. (Die `DEPLOY_WEBHOOK_URL`-Steps in den
API-CIs überspringen sich bei unbesetztem Secret lautlos.)

### 4.6 Verifikation

```sh
curl https://api.tracht-digital.de/                 # Prefix-Navigation (Gateway lebt)
curl https://api.tracht-digital.de/healthz          # aggregierte Service-Health
curl https://api.tracht-digital.de/content/blog     # → content-api
```

`BUILD_INFO.json` im Zielpfad zeigt, aus welchen Quell-Commits das laufende
Bundle gebaut wurde.

---

## 5. Reihenfolge beim Erst-Release (Checkliste)

1. ☐ DNS-Records anlegen, warten bis sie auflösen.
2. ☐ Plesk: Hauptdomain + 4 Subdomains + Let's Encrypt + HTTPS-Redirect.
3. ☐ `api.`: Git-Checkout (`build`), Docroot auf `gateway/public`, DBs + DB-User,
   `.env`-Dateien + `private.pem`, Migrationen, Service-Prozesse starten,
   Deploy-Aktionen + Webhook-Secret setzen, `/healthz` grün.
4. ☐ Frontends: Git-Checkout (`build`) je Subdomain, PHP aus,
   Webhook-Secrets in den vier Frontend-Repos setzen.
5. ☐ **`tds-blog` neu bauen** (Workflow „Build" manuell dispatchen), sobald die
   API live ist — die statischen Blog-Seiten backen ihre Artikel zur Build-Zeit
   und sind bis dahin leer (siehe Issue #1).
6. ☐ End-to-End prüfen: Login `admin.`, Login `app.`, Kontaktformular auf der
   Hauptdomain, Blog-Artikel sichtbar.

## Stolperfallen

- **`api.` antwortet 404 auf alles** → Docroot zeigt nicht auf `gateway/public`,
  oder das `.htaccess`-Rewrite greift nicht (mod_rewrite/AllowOverride prüfen).
- **`/healthz` meldet einzelne Services down** → der jeweilige
  `php -S`-Prozess läuft nicht (Watchdog-Task prüfen) oder Port-Mismatch
  zwischen Prozess und `*_UPSTREAM`.
- **Frontend-CI grün, aber Site nicht aktualisiert** → Webhook-Secret fehlt/falsch;
  die CI wertet das nur als gelbe Warnung, nie als roten Build
  (Annotations des Runs prüfen).
- **Login funktioniert auf `admin.`, aber nicht auf `app.`** → Cookie-Domain:
  beide Subdomains müssen über HTTPS unter `.tracht-digital.de` laufen.

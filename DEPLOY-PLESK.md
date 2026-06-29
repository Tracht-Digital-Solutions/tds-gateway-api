# Release auf Plesk — Domains, Subdomains, ein API-Projekt

Diese Anleitung beschreibt das komplette Produktions-Release der TDS-Plattform auf
einem Plesk-Host (Obsidian, mit Git-Extension, PHP 8.3 FPM, MariaDB, Let's Encrypt).
Sie deckt **alle fünf Web-Properties** ab: vier statische Frontends und das
API-Bundle, das Gateway + alle vier PHP-Services als **ein Plesk-Projekt**
(eine Subdomain, ein Git-Checkout) deployt.

Jedes Repo hat zwei Artefakt-Branches (der alte `build`-Branch existiert nicht
mehr): **`dev`** (Developer-Version, CI baut sie automatisch nach jedem
`main`-Push, **wird nicht deployt**) und **`release`** (Produktion, **nur per
manuellem Actions-Knopf** *Release → Run workflow*). **Plesk zieht immer den
`release`-Branch** — nie `main`, nie `dev`. Vor dem ersten Pull also je Repo
einmal den Release-Workflow ausführen, damit der `release`-Branch existiert.

| (Sub)Domain | Repo | Branch | Art |
|---|---|---|---|
| `tracht-digital.de` | `tds-landingpage` | `release` | statisch (HTML/CSS/JS) |
| `blog.tracht-digital.de` | `tds-blog` | `release` | statisch |
| `admin.tracht-digital.de` | `tds-admin` | `release` | statisch |
| `app.tracht-digital.de` | `tds-customer` | `release` | statisch |
| `api.tracht-digital.de` | `tds-api-gateway` | `release` | PHP-Bundle: Gateway + `services/{auth,contact,content,customer}` |

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

1. *Git → Repository hinzufügen*: Repo-URL, **Branch `release`**, Deploy-Modus
   „automatisch", Zielpfad = Docroot der (Sub)Domain.
2. *Hosting-Einstellungen*: **PHP deaktivieren** (rein statische Auslieferung),
   bevorzugt „nginx direkt ausliefern" für statische Dateien.
3. **Deploy-Webhook verdrahten**: Die Git-Extension zeigt eine
   *Webhook-URL* („Repository aktualisieren") an. Diese URL als Secret
   `DEPLOY_WEBHOOK_URL` im **jeweiligen Frontend-Repo** auf GitHub hinterlegen.
   Die CI pingt sie **per POST** nach jedem **Release** (nicht auf `dev`) — Plesks
   Git-Webhook beantwortet **nur POST**, ein bloßes GET liefert **404**. Plesk
   pullt dann und die Seite ist live. (Der Token steckt in der URL selbst;
   nirgendwo sonst ablegen.)
4. `admin.tracht-digital.de` ist `noindex` und per Token-Gate geschützt; wer
   zusätzlich abdichten will, legt in Plesk eine IP-Beschränkung oder BasicAuth
   davor (optional).

## 4. `api.tracht-digital.de` — das API-Bundle als ein Projekt

Gateway + alle vier Services laufen als **ein** Plesk-Projekt (eine Subdomain,
ein Git-Checkout). Für die PHP-Abhängigkeiten gibt es zwei Wege — dieser
Plesk-Host bringt einen **eingebauten PHP-Composer** mit
(*Websites & Domains → PHP Composer*), du bist also nicht auf das vorgebaute
Bundle angewiesen:

- **A — `release`-Bundle (empfohlen, kein Composer-Schritt).** Der
  `release`-Branch enthält Gateway + `services/{auth,contact,content,customer}`
  inklusive aller `vendor/` (mit Phinx). Auschecken, fertig — Plesk muss nichts
  installieren.
- **B — `main` + Plesk-Composer.** Statt des Bundles den `main`-Branch
  auschecken und in Plesk unter *PHP Composer* einmal `composer install
  --no-dev` für das Gateway **und** für jeden `services/<name>/` ausführen.
  Praktisch, wenn du ohne den Release-Knopf direkt vom Quellstand deployen
  willst; sonst identisch zu Weg A.

Bundle-Layout (`release`-Branch):

```
gateway/            ← Slim-Proxy (Docroot zeigt auf gateway/public)
services/auth/      ← tds-auth-api      (Port 8003)
services/contact/   ← tds-contact-api   (Port 8002)
services/content/   ← tds-content-api   (Port 8001)
services/customer/  ← tds-customer-api  (Port 8004)
Procfile, services.json, BUILD_INFO.json
```

### 4.1 Git + Docroot

1. *Git → Repository hinzufügen* auf der Subdomain `api.`: dieses Repo,
   **Branch `release`** (Weg A) bzw. **`main`** (Weg B — danach in Plesk
   *PHP Composer* `composer install --no-dev` pro `services/<name>/` + Gateway),
   Zielpfad = Docroot-Verzeichnis der Subdomain (z. B. `api.tracht-digital.de`-Ordner).
2. *Hosting-Einstellungen*: **Docroot auf `<zielpfad>/gateway/public`** stellen,
   PHP 8.3 (FPM) aktivieren.

Damit läuft das Gateway als gewöhnliche PHP-FPM-App — das mitgelieferte
`gateway/public/.htaccess` macht das Front-Controller-Rewrite unter Apache.
Im Standardmodus (`GATEWAY_MODE=inprocess`) bedient dieser **eine** FPM-Prozess
die gesamte API-Fläche; der `gateway:`-Eintrag im `Procfile` (eigener Prozess
auf `:8000`) wird **nicht** benötigt.

### 4.2 Keine Service-Prozesse nötig (Standard: In-Process)

Im Standardmodus `GATEWAY_MODE=inprocess` lädt das Gateway die App jedes Service
direkt aus `services/<name>/` (jeweils eigenes `vendor/`) und führt sie **im
selben PHP-FPM-Request** aus. Es laufen **keine** `php -S`-Prozesse, kein
Supervisor, kein Watchdog — PHP-FPM bedient alle vier APIs on demand. Genau das
macht „auf Plesk ohne SSH installieren und starten" möglich: Nach 4.1 (Docroot
auf `gateway/public`) ist die Plattform startklar, sobald 4.3/4.4 (`.env` +
Migrationen — am einfachsten über `/install.php`) erledigt sind. **Es gibt
nichts zu starten.**

Die Service-Verzeichnisse müssen neben dem Gateway liegen
(`<zielpfad>/services/<name>`, genau so wie das Bundle es ausliefert).
Abweichende Layouts über `GATEWAY_SERVICES_DIR` in der Gateway-`.env`.

> **Proxy-Modus (optional — nur für Nicht-Plesk-Hosts / ohne FPM).** Wer die
> Services lieber als eigene Loopback-Prozesse fährt, setzt `GATEWAY_MODE=proxy`
> in der Gateway-`.env` und startet sie mit `deploy/supervisor.conf.example`
> (mit Root) **oder** `gateway/bin/start-stack.sh` + *Geplante Aufgaben*
> (`@reboot` + 5-Minuten-Watchdog). Ports: auth 8003, contact 8002,
> content 8001, customer 8004. Auf Plesk im Normalfall **nicht** nötig.

### 4.3 `.env` + Schlüssel je Service

> **Schnellweg (Web-Installer):** Sobald der Docroot steht (4.1), erledigt der
> mitgelieferte Assistent unter **`https://api.tracht-digital.de/install.php`**
> die Punkte 4.3 + 4.4 auf einmal: DB-Verbindung testen/anlegen, alle
> `services/<name>/.env` schreiben, das Auth-RS256-Keypair erzeugen und die
> Migrationen ausführen. Danach **`gateway/public/install.php` löschen** (der
> Assistent bietet das selbst an) und ihn während der Einrichtung per
> IP/Passwort schützen. Wer es lieber von Hand macht, folgt 4.3 + 4.4.

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
- **gateway**: braucht standardmäßig **keine** `.env` — `GATEWAY_MODE=inprocess`
  ist der Default und findet die Services unter `services/<name>`. Nur für das
  interne `/wiki` zusätzlich `ADMIN_TOKEN` setzen (gleicher Admin-Token; ohne ihn
  ist `/wiki` 404). (`/install.php` schreibt diese Gateway-`.env` mit.)

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

   Bequemer: das mitgelieferte `gateway/bin/migrate-stack.sh` macht genau diese
   Schleife — `PHP_BIN=/opt/plesk/php/8.3/bin/php gateway/bin/migrate-stack.sh`.

### 4.5 Deploy-Aktionen + Webhook

In der Git-Extension der `api.`-Subdomain unter *„Bereitstellungsaktionen"*
hinterlegen (läuft nach jedem Pull):

```sh
for name in auth contact content customer; do
  (cd "services/$name" && /opt/plesk/php/8.3/bin/php vendor/bin/phinx migrate -e production)
done
```

Im In-Process-Modus sind **keine Prozess-Neustarts** nötig — das Gateway baut die
Service-App bei jedem Request frisch, neuer Code greift sofort. Hält OPcache nach
einem Pull alte Dateien, in Plesk einmal *PHP-FPM neu laden* (oder
`opcache.validate_timestamps` an lassen). Bequemer als die Schleife oben:
`PHP_BIN=/opt/plesk/php/8.3/bin/php gateway/bin/migrate-stack.sh`.

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
3. ☐ **In allen 5 Repos einmal den `release`-Workflow** (*Actions → Release → Run
   workflow*) ausführen, damit der `release`-Branch existiert.
4. ☐ `api.`: Git-Checkout (`release`), Docroot auf `gateway/public`, DBs + DB-User,
   `.env`-Dateien + `private.pem`, Migrationen (am einfachsten alles via
   `/install.php`), Deploy-Aktionen + Webhook-Secret setzen, `/healthz` grün.
   **Keine Service-Prozesse zu starten** (In-Process-Modus).
5. ☐ Frontends: Git-Checkout (`release`) je Subdomain, PHP aus,
   Webhook-Secrets in den vier Frontend-Repos setzen.
6. ☐ **`tds-blog` neu releasen** (Workflow „Release" manuell dispatchen), sobald die
   API live ist — die statischen Blog-Seiten backen ihre Artikel zur Build-Zeit
   und sind bis dahin leer (siehe Issue #1).
7. ☐ End-to-End prüfen: Login `admin.`, Login `app.`, Kontaktformular auf der
   Hauptdomain, Blog-Artikel sichtbar.

## Stolperfallen

- **`api.` antwortet 404 auf alles** → Docroot zeigt nicht auf `gateway/public`,
  oder das `.htaccess`-Rewrite greift nicht (mod_rewrite/AllowOverride prüfen).
- **`/healthz` meldet einzelne Services down** → im In-Process-Modus meist eine
  fehlende/falsche `services/<name>/.env` oder eine nicht erreichbare DB; bei
  „status 0" fehlt das Service-Verzeichnis bzw. dessen `vendor/` (Bundle prüfen,
  `GATEWAY_SERVICES_DIR`). Im Proxy-Modus: der jeweilige `php -S`-Prozess läuft
  nicht (Watchdog-Task) oder Port-Mismatch zum `*_UPSTREAM`.
- **Frontend-CI grün, aber Site nicht aktualisiert** → Webhook-Secret fehlt/falsch;
  die CI wertet das nur als gelbe Warnung, nie als roten Build
  (Annotations des Runs prüfen). Zeigt die Warnung **HTTP 404**, obwohl Host/Port
  (`:8443`) stimmen, ist meist der Token/Pfad veraltet — die aktuelle Webhook-URL
  aus Plesk neu kopieren. (Die CI ruft den Hook korrekt **per POST** auf; ein GET
  liefert bei Plesk grundsätzlich 404.)
- **Login funktioniert auf `admin.`, aber nicht auf `app.`** → Cookie-Domain:
  beide Subdomains müssen über HTTPS unter `.tracht-digital.de` laufen.

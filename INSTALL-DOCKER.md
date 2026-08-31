# INSTALL — Alles per Docker mit einem Befehl

Das mitgelieferte `Dockerfile` packt das Gateway **und** die Backends in ein
einziges Image; `docker-compose.yml` ergänzt MariaDB. Ein Befehl bringt die
komplette API-Plattform hoch.

## Ein Prozess für alle Backends (Default)

Der Container läuft standardmäßig mit `GATEWAY_MODE=inprocess`: das Gateway lädt
die Slim-App jedes Services **in seinen eigenen Prozess**. Es läuft also genau
ein `php -S` — kein Service-Prozess, keine Loopback-Ports. Das entspricht dem
Produktions-Host (eine PHP-FPM-App).

Bis v0.4.9 startete supervisord trotzdem *immer* alle vier Prozesse, obwohl der
Default schon `inprocess` war. Die drei Loopback-Server auf 8003/8004/8100 liefen
also dauerhaft mit und wurden **nie angesprochen** — sie antworteten aber auf
ihren Ports und sahen dadurch beim Debuggen wie der echte Request-Pfad aus.
Seit v0.4.10 hängen sie an `TDS_BACKEND_AUTOSTART`, das der Entrypoint aus
`GATEWAY_MODE` ableitet.

Prüfen, was tatsächlich läuft:

```bash
docker logs tds-gateway-api-api-1 | grep GATEWAY_MODE
# [entrypoint] GATEWAY_MODE=inprocess (loopback backends autostart: false)
```

Proxy-Modus (getrennte Prozesse) für Debugging — über `.env.docker`, **nicht**
über eine Shell-Variable:

```bash
echo "GATEWAY_MODE=proxy" > .env.docker
docker compose up -d --force-recreate api
```

> **`GATEWAY_MODE` bewusst nicht in `docker-compose.yml` gemappt.** Compose
> substituiert aus der Projekt-`.env` — und das ist hier die *lokale App-Konfig*
> für `composer start`, die noch `GATEWAY_MODE=proxy` und die alte
> `GATEWAY_SERVICES`-Liste von vor dem Cutover enthält. Ein Mapping ließe diese
> veraltete Datei still den Container-Modus umschalten.

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
    tds-ext-time-tracker-pkg/     tds-ext-customers-pkg/       tds-ext-billing-pkg/
    tds-ext-lexware-pkg/          tds-ext-tools-pkg/           tds-ext-messages-pkg/
    tds-ext-projects-pkg/         tds-ext-documents-pkg/       tds-ext-support-tickets-pkg/
    tds-ext-contact-tickets-pkg/  tds-ext-website-cms-pkg/     tds-ext-blog-cms-pkg/
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

`API_PORT` (Default `8080`) ist Host- **und** Container-Port: das Gateway
lauscht auf derselben Nummer, unter der es veröffentlicht wird. Damit bedeutet
`http://localhost:8080` überall dasselbe — im Browser, im API-Container und in
den Frontend-Containern, die sich dieses Netzwerk teilen. Bis zum 31.08.2026
lauschte es fest auf `8000`; das reicht für einen Browser und für sonst nichts
(siehe `frontends`-Profil unten).

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
| `CORS_ALLOWED_ORIGINS` | jede Browser-Herkunft, die mit Anmeldedaten rufen darf — **exakt** benannt, denn `*` ist mit Credentials verboten |

Drittanbieter-Keys (Stripe, DeepL, Lexware, GitHub-Rebuild) werden **nicht**
hier gesetzt, sondern zur Laufzeit im Admin-Frontend („Einstellungen“).

**Zwei getrennte Dateien, und der Grund ist kein Geschmack.** `.env.docker` ist
die `env_file` des **API**-Containers. Die drei öffentlichen Sites lesen ihre
Zugangsdaten aus **`.env.frontends`** (Vorlage: `.env.frontends.example`) —
`TDS_SITE_KEY` und `TDS_CACHE_TOKEN`, beide optional, beide server-only.

Der Grund: Compose ersetzt `${…}` in `docker-compose.yml` aus der
Projekt-`.env`, und das ist in diesem Repo die App-Konfiguration für
`composer start`, nicht die Container-Konfiguration. Ein Wert für ein Frontend
in `.env.docker` wäre also zur leeren Zeichenkette geworden, während die Site
`cache_token_not_configured` meldet und man auf genau den Wert schaut, den man
gerade gesetzt hat. Deshalb `env_file` statt Interpolation.

Eine bereits vorhandene `services/<name>/.env` (z. B. per Volume gemountet)
gewinnt immer — der Entrypoint überschreibt sie nicht.

## Die Frontends mitlaufen lassen (`frontends`-Profil)

Sechs Produkte, ein Profil: die fünf server-gerenderten Astro-Anwendungen —
Landingpage, Blog, Tools, Admin-Panel, Kundenportal — laufen je in einem
eigenen Node-Container aus ihrem gepackten `release/`-Baum
(`deploy/Dockerfile.frontend`); die Login-Site ist als einzige noch statisch
und wird von einem schlanken nginx ausgeliefert.

| Port | Produkt | Repo |
|---|---|---|
| 4321 | Landingpage | `tds-landingpage-frontend` |
| 4322 | Blog | `tds-blog-frontend` |
| 4323 | Admin-Panel | `tds-admin-frontend` |
| 4324 | Kundenportal | `tds-customer-frontend` |
| 4325 | Tools | `tds-tools-frontend` |
| 4326 | Login-Site | `tds-auth-frontend` |

### Schritt 1 — bauen, und zwar mit der lokalen API im Environment

**Das ist der Schritt, den man nicht überspringen kann.** Ein Frontend
entscheidet zur **Build-Zeit**, mit welcher API es spricht; die Werte werden
ins Bundle einkompiliert. Ein Baum, der gegen die Produktion gebaut wurde,
redet im Container weiter mit der Produktion — und wenn er es nicht darf, sieht
man davon nichts als eine ruhige, leere Oberfläche.

Vier verschiedene Variablennamen für dieselbe Sache, historisch gewachsen:

| Produkt | Variable(n) | Form |
|---|---|---|
| Landingpage | `PUBLIC_CONTENT_API_URL`, `PUBLIC_CONTACT_API_URL` | mit `/content`- bzw. `/contact`-Suffix |
| Blog | `CONTENT_API_URL` (server-seitig), `PUBLIC_CONTENT_API_URL` | mit `/content`-Suffix |
| Tools | `PUBLIC_API_URL` | nackte Herkunft |
| Admin + Portal | `PUBLIC_API_BASE`, `PUBLIC_AUTH_API_URL`, `PUBLIC_LOGIN_URL` | nackte Herkunft (`/auth` beim zweiten) |
| Login-Site | `PUBLIC_AUTH_API_URL` | mit `/auth`-Suffix |

In der PowerShell, je im Repo des Produkts:

```powershell
# Admin-Panel und Kundenportal
$env:PUBLIC_API_BASE     = "http://localhost:8080"
$env:PUBLIC_AUTH_API_URL = "http://localhost:8080/auth"
$env:PUBLIC_LOGIN_URL    = "http://localhost:4326"
npm run build

# Login-Site
$env:PUBLIC_AUTH_API_URL = "http://localhost:8080/auth"
npm run build

# Landingpage
$env:PUBLIC_CONTENT_API_URL = "http://localhost:8080/content"
$env:PUBLIC_CONTACT_API_URL = "http://localhost:8080/contact"
$env:PUBLIC_BLOG_BASE_URL   = "http://localhost:4322"
npm run build

# Blog
$env:CONTENT_API_URL        = "http://localhost:8080/content"
$env:PUBLIC_CONTENT_API_URL = "http://localhost:8080/content"
npm run build

# Tools
$env:PUBLIC_API_URL   = "http://localhost:8080"
$env:PUBLIC_LOGIN_URL = "http://localhost:4326"
npm run build
```

Jedes Repo hat für dieselben Werte eine `.env.example`; wer dauerhaft lokal
arbeitet, legt sich daraus eine `.env` an und baut ohne Shell-Variablen.
`npm run build` erzeugt `dist/` **und** (per `postbuild`) den `release/`-Baum,
den die Container brauchen.

### Schritt 2 — starten

```bash
docker compose --profile frontends up -d --build
```

Der erste Build dauert; danach cacht BuildKit die Installationsschicht je
Produkt. Ein einzelnes Frontend aktualisierst du mit
`docker compose up -d --build <dienst>` — die Compose-Dienste heißen
`landingpage`, `blog`, `admin`, `customer`, `tools`, `auth-web`, nicht wie die
Repos.

> **Alle sieben Prozesse teilen sich ein Netzwerk** (`network_mode:
> "service:api"`). Deshalb bedeutet `localhost:8080` überall dasselbe — im
> Browser, im API-Container und in jedem Frontend-Container. Das ist keine
> Kosmetik: die öffentlichen Sites lesen ihre Inhalte **serverseitig**, und ein
> Container mit eigenem Netzwerk erreicht unter `localhost:8080` nur sich
> selbst. Alle Inhaltsabrufe sind absichtlich fehlertolerant, die Site würde
> also mit `200` und den mitgelieferten Platzhaltern antworten und dabei
> kerngesund aussehen. Der Preis: `docker compose restart api` nimmt den
> Frontends kurz die Netzwerkverbindung mit.

### Schritt 3 — den ersten Admin anlegen

Der geteilte `ADMIN_TOKEN` ist kein Login mehr; echte Admins sind
`app_user`-Zeilen.

```bash
docker compose exec api php services/auth/bin/create-admin.php admin@local.test 'EinPasswort!2026'
```

Wiederholbar: eine vorhandene E-Mail wird zum Admin befördert und reaktiviert,
das Passwort ändert sich nur, wenn eines mitgegeben wird.

### Schritt 4 — Site und Blog im Panel anlegen

**Eine frische Datenbank hat weder eine CMS-Site noch einen Blog.** Ohne beides
liefern Landingpage und Blog ihre mitgelieferten Texte aus — korrekt, aber
verwechselbar mit „die API ist nicht angebunden". Im Panel:

- **Website-CMS → Site anlegen**, Schlüssel `landingpage`.
- **Blog-CMS → Blog anlegen**, Schlüssel `blog`.

Danach zeigt jede Änderung an einer Sektion oder einem Beitrag auf der
jeweiligen Site — nach einem Cache-Neubau, siehe Schritt 6.

### Schritt 5 — nachweisen, dass die Anbindung wirklich steht

Anmelden auf `http://localhost:4326`, danach `http://localhost:4323`. Dann im
Netzwerk-Panel des Browsers **eine** Anfrage aufmachen und drei Dinge prüfen:

1. Die Anfrage geht an `http://localhost:8080/...` — **absolut**, nicht relativ
   an `localhost:4323`.
2. Sie antwortet `200`. Nicht `401`, und vor allem nicht `401 nach fünf
   Sekunden` (dazu unten mehr).
3. Die Antwort trägt `Access-Control-Allow-Origin: http://localhost:4323` und
   `Access-Control-Allow-Credentials: true`.

Fehlt Punkt 3, verwirft der Browser die Antwort, **bevor** der Anwendungscode
sie sieht. Serverseitig ist das kein Fehler; im Log steht nichts; die
Oberfläche zeigt exakt das, was sie auch bei „keine Daten" zeigt. Deshalb ist
das hier eine Prüfung und kein Sichtbefund.

Ohne Browser geht dieselbe Frage per curl:

```bash
# CORS für eine Herkunft
curl -s -i -X OPTIONS http://localhost:8080/content/blog \
  -H 'Origin: http://localhost:4322' \
  -H 'Access-Control-Request-Method: GET' | grep -i access-control-allow-origin

# Eine echte Panel-Route mit Sitzung. Erwartet: 200 in Millisekunden.
TOK=$(curl -s -X POST http://localhost:8080/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@local.test","password":"EinPasswort!2026"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
curl -s -o /dev/null -w 'status=%{http_code} time=%{time_total}s\n' \
  -H "Cookie: tds_session=$TOK" http://localhost:8080/tickets/summary
```

> **`status=401 time=5.0s` hat genau eine Ursache.** Der Frontend-Dienst läuft
> in-process im Gateway und holt zur JWT-Prüfung die JWKS über HTTP von
> `AUTH_API_URL` — also von **diesem** Server. `php -S` beantwortet ohne
> `PHP_CLI_SERVER_WORKERS` immer nur eine Anfrage gleichzeitig, die
> Selbstanfrage kommt nie dran, läuft nach fünf Sekunden in den Timeout, der
> Prüfer hat keine Schlüssel, und die Route antwortet anonym → 401. Der
> Entrypoint setzt den Wert deshalb auf 8. Erkennbar ist der Zustand nur an der
> Zeit: der Login funktioniert (auth-api wird direkt erreicht), die Shell
> rendert, und jede Datenabfrage ist ruhig leer.

### Schritt 6 — Inhalt ändern und live sehen

Der Rückkanal (Panel ruft die Site, damit sie ihre Seiten neu baut) braucht das
Cache-Token aus `.env.frontends` **und** eine hinterlegte Adresse der Site. Die
Adresse kommt aus der Kopplung über `/install` — und die geht lokal nicht
(siehe unten). Deshalb meldet das Panel beim Speichern
`cache_status: "not_configured"`, was hier korrekt und harmlos ist: die Seite
wird nicht automatisch neu gebaut.

Von Hand ist es ein Aufruf:

```bash
curl -s -X POST -H 'x-tds-cache-token: dev-cache-token' \
  -H 'Content-Type: application/json' -d '{"all":true}' \
  http://localhost:4321/tds/cache/rebuild
```

Antwortet `/tds/cache/status` mit `503`, ist auf dem Container gar kein Token
gesetzt; `401` heißt, es stimmt nicht mit `.env.frontends` überein.

### Was der Container-Stack absichtlich nicht kann

**Kopplung über `/install`.** Der Assistent koppelt eine *deployte* Site mit
der API und legt die Zugangsdaten außerhalb des Checkouts ab. Auf `localhost`
funktioniert er nicht: der Verbindungsspeicher in `tds-shared/connection`
akzeptiert beim Schreiben nur `https:`-Herkünfte, der Versuch kommt also bis
zum Speichern und scheitert dort mit `state_write_failed` (500). Lokal sind
`TDS_SITE_KEY` und `TDS_CACHE_TOKEN` aus `.env.frontends` die Verbindung.

**Das Panel ohne Neubau umhängen.** Ein Panel liest kein `tds-runtime.json`:
die Shell schreibt `<meta name="tds-api-base">`, und `runtimeConfig()` in
`tds-shared/api` gibt auf, sobald dieses Tag da ist. Eine andere API heißt:
Schritt 1 wiederholen.

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

## Was der Build aus den Nachbar-Repos NICHT mitnimmt

Jedes als Build-Kontext genutzte Repo (`../tds-auth-api`, `../tds-customer-api`,
`../tds-core-frontend-api`, `../tds-frontend-contract-pkg`, alle 14
`../tds-ext-*-pkg`) hat eine eigene `.dockerignore`. Drei Einträge darin sind
nicht bloß Kosmetik — ohne sie **lügt der Container über sich selbst**:

| Ausgeschlossen | Sonst passiert |
|---|---|
| `node_modules`, `vendor`, `.git`, `dist` | Der Kontext-Transfer dauert länger als der ganze Rest des Images (eine Extension allein: 150 MB+) |
| `.env` | Die lokale Entwickler-`.env` landet im Image, der Entrypoint meldet *„keeping existing .env"* und schreibt die Container-Defaults **nicht** — der Dienst zeigt auf eine Datenbank, die es dort nicht gibt, und die Zugangsdaten stecken in einem Image-Layer |
| `var` | Darin liegt der Marker des In-Process-Auto-Migrators (`.migrated-<hash>`). Mitkopiert hält er eine **frische, leere** Datenbank für migriert und überspringt alles. Symptom: Dienst startet grün und wirft bei der ersten Abfrage *„Base table or view not found"* |

Seit dem 31.08.2026 haben auch die fünf **Frontend**-Repos eine
`.dockerignore`, aus zwei der drei Gründe oben. `**/node_modules` trifft dort
zwei Bäume — den Entwicklungsbaum und den in `release/`, zusammen ~170 MB je
Site —, und beide sind für die Maschine des Entwicklers installiert; unter
Windows enthalten sie `win32`-Binaries (`sharp`), die Linux nicht laden kann.
Das Image installiert stattdessen aus `release/package.json` neu, die nur
öffentliche Pakete listet — deshalb braucht kein Frontend-Container ein
`NPM_TOKEN`.

Vier weitere Fallen, alle im Container reproduziert:

**`php -S` muss sich selbst antworten können.** Der eingebaute Server bedient
ohne `PHP_CLI_SERVER_WORKERS` immer nur **eine** Anfrage gleichzeitig. Im
Default-Modus `inprocess` läuft der Frontend-Dienst im Gateway-Prozess und holt
zur JWT-Prüfung die JWKS über HTTP von `AUTH_API_URL` — also von genau diesem
Server. Die Selbstanfrage kommt nie dran, läuft nach fünf Sekunden in den
Timeout, der Prüfer hat keine Schlüssel, und jede Route antwortet anonym: 401.
Der Entrypoint setzt den Wert deshalb auf 8. Erkennbar ist der Zustand nur an
der **Zeit** — `status=401 time=5.0s` —, denn nichts ist fehlgeschlagen: eine
Anfrage ohne Schlüssel ist einfach nicht authentifiziert, das Panel meldet sich
normal an und zeigt danach überall seinen gewöhnlichen Leerzustand. Auf dem
Produktions-Host stellt sich die Frage nicht; PHP-FPM hat seit jeher einen
Prozess-Pool.

**Der Gateway-Port im Container muss dem veröffentlichten entsprechen.**
`API_PORT` ist beides. Waren sie verschieden (`8080:8000`), funktionierte der
Browser und sonst nichts: die öffentlichen Sites lesen ihre Inhalte
**serverseitig** von der URL, die ihr Build eingebacken hat, und im Container
lauschte dort niemand. Alle Inhaltsabrufe sind absichtlich fehlertolerant, also
antwortete die Site `200` mit den mitgelieferten Platzhaltern und sah
kerngesund aus.

Und zwei ältere:

**PHP-Version, für die Composer auflöst.** Der Dockerfile pinnt sie über
`ARG RUNTIME_PHP`. Das Builder-Image (`composer:2`) bringt eine neuere
PHP-Version mit als die Laufzeit (`php:8.3`); ohne den Pin wählt Composer für
den Frontend-Dienst — den einzigen, der mit `composer update` installiert wird
— Pakete, die PHP ≥ 8.4 verlangen. Composer schreibt das in
`vendor/composer/platform_check.php`, und die Laufzeit stirbt schon beim
`require vendor/autoload.php`. Sichtbar ist davon nur
`"/frontend": {"status": 0}` in `/healthz` plus 502 auf jeder Route — kein
einziger Eintrag im Anwendungslog, weil der Fehler im Autoloader passiert.
Halte den Wert gleich der PHP-Version der Runtime-Stage (und gleich dem
`php-version` in `_assemble.yml`, das die ausgelieferte Variante schützt).

**Werte mit Leerzeichen in einer generierten `.env` gehören in
Anführungszeichen.** phpdotenv lehnt einen unquotierten Wert mit Leerzeichen ab
(*„Failed to parse dotenv file. Encountered unexpected whitespace at […]"*), und
`Bootstrap::createApp()` lädt die `.env` als Allererstes — eine solche Zeile
reißt damit den **kompletten Dienst** beim Start um, nicht nur die betroffene
Einstellung. `MAIL_FROM_NAME=Tracht Digital Solutions` hat genau das getan:
auth und customer, deren Werte zufällig keine Leerzeichen enthalten, blieben
grün, während jede Frontend-Route 500 lieferte.


## Production-Hinweis

Das Image eignet sich auch für Single-Host-Prod: echte Secrets in
`.env.docker`, einen echten `JWT_PRIVATE_KEY` setzen, einen Reverse-Proxy
(TLS) vor `API_PORT` hängen und die MariaDB-Daten über das `db-data`-Volume
sichern. Für den klassischen Plesk-Weg (ohne Docker) siehe
[`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md).

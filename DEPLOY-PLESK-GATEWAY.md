# Gateway auf Plesk installieren (`api.tracht-digital.de`)

Fokussierte Anleitung **nur für das API-Bundle** auf einem Plesk-Host
(Obsidian, Git-Extension, PHP 8.3 FPM, MariaDB, Let's Encrypt). Das ganze
Plattform-Release (inkl. der vier Frontends) steht in
[`DEPLOY-PLESK.md`](./DEPLOY-PLESK.md); die Container-Variante in
[`INSTALL-DOCKER.md`](./INSTALL-DOCKER.md).

Ergebnis: Eine Subdomain `api.tracht-digital.de` liefert das Gateway unter
PHP-FPM aus, die vier Services laufen als lokale `php -S`-Prozesse, und
`bin/start-stack.sh` hält sie am Leben. **Sobald das Bundle ausgecheckt und
eingerichtet ist, laufen die vier APIs automatisch mit.**

> **Branch-Modell (seit Umstellung):** Der Host pullt den **`release`**-Branch
> (Production-Bundle). `release` wird **nur per Knopfdruck** gebaut
> (Actions → *Release assemble → release branch* → *Run workflow*). Der
> `dev`-Branch ist das automatisch nach jedem Push gebaute Entwickler-Bundle
> (nicht für Production). Den alten `build`-Branch gibt es nicht mehr.

## 1. Subdomain + SSL

1. `api.tracht-digital.de` als Subdomain anlegen (eigenes Docroot).
2. Let's-Encrypt-Zertifikat ausstellen, *„HTTP → HTTPS umleiten"* aktivieren.
   Die Subdomain muss unter `.tracht-digital.de` liegen (Cookie-Domain).
3. PHP **8.3 (FPM)** für die Subdomain aktivieren.

## 2. Git-Checkout des `release`-Branch

Das Repo ist privat → in der Plesk-Git-Extension per **SSH-URL** einbinden
(`git@github.com:Tracht-Digital-Solutions/tds-api-gateway.git`), den von Plesk
angezeigten Public Key im Repo unter *Settings → Deploy keys* (read-only)
hinterlegen.

- *Git → Repository hinzufügen*: dieses Repo, **Branch `release`**, Zielpfad =
  Docroot-Verzeichnis der Subdomain.

> Den `release`-Branch gibt es erst nach dem ersten Production-Build: einmal
> *Actions → „Release assemble → release branch" → Run workflow* ausführen,
> dann ist er da.

Der `release`-Branch enthält Gateway + `services/{auth,contact,content,customer}`
mit allen `vendor/` (inkl. Phinx). **Auf dem Host läuft kein `composer
install`.**

## 3. Docroot auf das Gateway zeigen

*Hosting-Einstellungen → Dokumentenstamm* auf `<zielpfad>/gateway/public`
setzen. Das mitgelieferte `gateway/public/.htaccess` macht das
Front-Controller-Rewrite — damit läuft das Gateway als normale PHP-FPM-App,
ein eigener Gateway-Prozess ist **nicht** nötig.

## 4. Datenbanken + `.env` je Service

> **Schnellweg:** Sobald der Docroot steht (Schritt 3), erledigt der
> Web-Installer unter **`https://api.tracht-digital.de/install.php`** die
> Punkte 4.1–4.4 (DB testen/anlegen, alle `.env` schreiben, Auth-Keypair,
> Migrationen). Danach `gateway/public/install.php` **löschen** und während
> der Einrichtung am besten per IP/Passwort schützen. Manuell geht es so:

1. In Plesk vier MariaDB-DBs + DB-User anlegen (`tds_auth`,
   `tds_contact_ratelimit`, `tds_content`, `tds_customer` — Namen müssen zur
   jeweiligen `.env` passen).
2. Pro Service `services/<name>/.env` aus `.env.example` befüllen
   (DB-Creds + Secrets). `.env`-Dateien sind nicht im Repo getrackt und
   überleben Deploys.
   - **auth**: zusätzlich `keys/private.pem` (Rechte `600`) nach
     `services/auth/keys/` kopieren — oder `JWT_PRIVATE_KEY` in die `.env`.
   - **contact**: `RESEND_API_KEY`.
   - **content/customer**: `ADMIN_TOKEN` (überall gleich), customer zusätzlich
     Stripe-Keys + `DOCUMENT_SIGN_SECRET`.
3. Migrationen aus dem Bundle:

   ```sh
   cd <zielpfad>
   PHP_BIN=/opt/plesk/php/8.3/bin/php gateway/bin/migrate-stack.sh
   ```

## 5. Die vier Service-Prozesse starten + am Leben halten

`bin/start-stack.sh` startet die vier Loopback-Services idempotent:

```sh
PHP_BIN=/opt/plesk/php/8.3/bin/php /var/www/vhosts/.../gateway/bin/start-stack.sh
```

In Plesk unter *Geplante Aufgaben* zweimal eintragen:

| Zeitplan | Befehl |
|---|---|
| `@reboot` | `PHP_BIN=/opt/plesk/php/8.3/bin/php <zielpfad>/gateway/bin/start-stack.sh` |
| alle 5 Min | `PHP_BIN=/opt/plesk/php/8.3/bin/php <zielpfad>/gateway/bin/start-stack.sh` |

Der 5-Minuten-Job ist der Watchdog: Da das Skript idempotent ist, startet es
nur abgestürzte/fehlende Prozesse neu.

> Mit Root-Zugang stattdessen `deploy/supervisor.conf.example` übernehmen
> (den `tds-gateway`-Block weglassen — das Gateway läuft ja unter PHP-FPM) und
> `supervisorctl reread && supervisorctl update`.

## 6. Deploy-Aktionen + Webhook

In der Git-Extension der `api.`-Subdomain unter *„Bereitstellungsaktionen"*
(läuft nach jedem Pull):

```sh
PHP_BIN=/opt/plesk/php/8.3/bin/php gateway/bin/migrate-stack.sh
pkill -f 'php -S 127.0.0.1:800' || true
PHP_BIN=/opt/plesk/php/8.3/bin/php gateway/bin/start-stack.sh
```

Die *Webhook-URL* dieser Git-Instanz als Secret `DEPLOY_WEBHOOK_URL` **im
tds-api-gateway-Repo** hinterlegen — und nur dort. Der Webhook wird **nur beim
Production-Build** gepingt (Actions → *Release assemble → release branch*), der
das `release`-Bundle baut; Plesk pullt `release` und führt die
Bereitstellungsaktionen aus. Ein Push in ein API-Repo baut nur das `dev`-Bundle
neu (kein Deploy) — Production geht immer über den Release-Knopf.

## 7. Verifikation

```sh
curl https://api.tracht-digital.de/            # Prefix-Navigation
curl https://api.tracht-digital.de/healthz     # alle vier Services grün
curl https://api.tracht-digital.de/content/blog
```

## Stolperfallen

- **404 auf alles** → Docroot zeigt nicht auf `gateway/public`, oder
  `mod_rewrite`/`AllowOverride` ist aus.
- **`/healthz` meldet einen Service down** → dessen `php -S`-Prozess läuft
  nicht (Watchdog-Cron prüfen) oder Port-Mismatch zu `*_UPSTREAM`.
- **`bin/*.sh` nicht ausführbar** → Git überträgt das x-Bit; bei Bedarf
  `chmod +x gateway/bin/*.sh` in den Bereitstellungsaktionen ergänzen.
- **Login auf `app.` schlägt fehl, auf `admin.` nicht** → beide Subdomains
  müssen über HTTPS unter `.tracht-digital.de` laufen (Cookie-Domain).

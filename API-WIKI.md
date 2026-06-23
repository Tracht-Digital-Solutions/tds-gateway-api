# TDS API — Wiki

> **Auto-generiert** von `tds-api-gateway/bin/gen-api-wiki.php` aus den
> Slim-Routendefinitionen der Services. Nicht von Hand bearbeiten — neue
> Routen erscheinen beim nächsten Build automatisch.

Öffentliche Basis-URL: `https://api.tracht-digital.de` · Stand: 2026-06-23 10:10 UTC · 58 Routen

Auth-Spalte: **öffentlich** (kein Gate) · **Admin-Token** (Bearer `ADMIN_TOKEN`) · **JWT** (Customer-Cookie/Bearer, JWKS-verifiziert) · **Customer-JWT** (Login-Session).

## Inhalt

- [API-Gateway](#api-gateway) — 3 Routen
- [Auth-API](#auth-api) — 10 Routen
- [Contact-API](#contact-api) — 2 Routen
- [Content-API](#content-api) — 15 Routen
- [Customer-API](#customer-api) — 28 Routen

## API-Gateway

Single public entry. Proxies every other path to the matching backend; only the two routes below are served by the gateway itself.

_Quelle: `src/Bootstrap.php`_

| Methode | Pfad | Auth | Handler |
|---|---|---|---|
| `GET` | `/` | öffentlich | `IndexAction` |
| `GET` | `/healthz` | öffentlich | `HealthAction` |
| `GET` | `/wiki` | öffentlich | `WikiAction` |

> Alle übrigen Pfade werden anhand des ersten Segments an den passenden
> Backend-Service weitergeleitet (`/auth/*`, `/content/*`, `/customer/*`,
> `/contact`). Siehe die Service-Abschnitte für die effektiven Pfade.

## Auth-API

RS256 JWT issuance + JWKS, admin + customer login, sessions.

_Quelle: `../tds-auth-api/src/Bootstrap.php`_

| Methode | Pfad | Auth | Handler |
|---|---|---|---|
| `GET` | `/auth/healthz` | öffentlich | `HealthAction` |
| `POST` | `/auth/admin/login` | öffentlich | `AdminLoginAction` |
| `DELETE` | `/auth/admin/login` | öffentlich | `AdminLogoutAction` |
| `POST` | `/auth/admin/customer-credentials` | Admin-Token | `CreateCustomerCredentialAction` |
| `GET` | `/auth/admin/sessions` | Admin-Token | `ListSessionsAction` |
| `DELETE` | `/auth/admin/sessions/{jti}` | Admin-Token | `RevokeSessionAction` |
| `POST` | `/auth/customer/login` | öffentlich | `CustomerLoginAction` |
| `PUT` | `/auth/customer/password` | Customer-JWT | `CustomerChangePasswordAction` |
| `POST` | `/auth/refresh` | öffentlich | `RefreshAction` |
| `GET` | `/auth/.well-known/jwks.json` | öffentlich | `JwksAction` |

## Contact-API

Contact form → Resend email, with per-IP rate limiting.

_Quelle: `../tds-contact-api/src/Bootstrap.php`_

| Methode | Pfad | Auth | Handler |
|---|---|---|---|
| `GET` | `/healthz` | öffentlich | `HealthAction` |
| `POST` | `/contact` | öffentlich | `SubmitContactAction` |

## Content-API

Blog posts + media; admin deployment/version panel.

_Quelle: `../tds-content-api/src/Bootstrap.php`_

| Methode | Pfad | Auth | Handler |
|---|---|---|---|
| `GET` | `/content/healthz` | öffentlich | `HealthAction` |
| `GET` | `/content/blog` | öffentlich | `ListAction` |
| `GET` | `/content/blog/{slug}` | öffentlich | `GetAction` |
| `POST` | `/content/blog` | Admin-Token | `CreateAction` |
| `PUT` | `/content/blog/{slug}` | Admin-Token | `UpdateAction` |
| `DELETE` | `/content/blog/{slug}` | Admin-Token | `DeleteAction` |
| `POST` | `/content/blog/{slug}/cover` | Admin-Token | `UploadCoverAction` |
| `POST` | `/content/blog/{slug}/image` | Admin-Token | `UploadBodyImageAction` |
| `GET` | `/content/landing` | öffentlich | `LandingListAction` |
| `GET` | `/content/landing/{key:[a-z0-9-]+}` | Admin-Token | `LandingGetAction` |
| `PUT` | `/content/landing/{key:[a-z0-9-]+}` | Admin-Token | `LandingPutAction` |
| `DELETE` | `/content/landing/{key:[a-z0-9-]+}` | Admin-Token | `LandingDeleteAction` |
| `GET` | `/content/admin/deployments` | Admin-Token | `ListDeploymentsAction` |
| `POST` | `/content/admin/deployments/{name}/update` | Admin-Token | `TriggerDeploymentAction` |
| `GET` | `/content/uploads/{slug:[a-z0-9-]+}/{filename:[a-zA-Z0-9._-]+}` | öffentlich | `UploadServeAction` |

## Customer-API

Customers, projects, invoices (Stripe), documents, messages, time tracking, Lexware export.

_Quelle: `../tds-customer-api/src/Bootstrap.php`_

| Methode | Pfad | Auth | Handler |
|---|---|---|---|
| `GET` | `/customer/healthz` | öffentlich | `HealthAction` |
| `POST` | `/customer/stripe/webhook` | öffentlich | `WebhookAction` |
| `GET` | `/customer/documents/sign` | öffentlich | `SignedDownloadAction` |
| `POST` | `/customer/admin/customers` | Admin-Token | `CreateCustomerAction` |
| `GET` | `/customer/admin/projects` | Admin-Token | `AdminListProjectsAction` |
| `GET` | `/customer/admin/time-entries` | Admin-Token | `AdminTimeEntryListAction` |
| `POST` | `/customer/admin/time-entries` | Admin-Token | `AdminTimeEntryCreateAction` |
| `GET` | `/customer/admin/time-entries/timer` | Admin-Token | `AdminTimerCurrentAction` |
| `POST` | `/customer/admin/time-entries/timer/start` | Admin-Token | `AdminTimerStartAction` |
| `POST` | `/customer/admin/time-entries/timer/stop` | Admin-Token | `AdminTimerStopAction` |
| `POST` | `/customer/admin/time-entries/export-lexware` | Admin-Token | `AdminTimeEntryExportLexwareAction` |
| `PATCH` | `/customer/admin/time-entries/{id:[0-9]+}` | Admin-Token | `AdminTimeEntryUpdateAction` |
| `DELETE` | `/customer/admin/time-entries/{id:[0-9]+}` | Admin-Token | `AdminTimeEntryDeleteAction` |
| `GET` | `/customer/me` | JWT | `GetMeAction` |
| `PATCH` | `/customer/me` | JWT | `UpdateMeAction` |
| `GET` | `/customer/projects` | JWT | `ProjectListAction` |
| `GET` | `/customer/projects/{id:[0-9]+}` | JWT | `ProjectGetAction` |
| `GET` | `/customer/projects/{id:[0-9]+}/time-entries` | JWT | `TimeEntryListAction` |
| `GET` | `/customer/invoices` | JWT | `InvoiceListAction` |
| `POST` | `/customer/invoices/{id:[0-9]+}/pay` | JWT | `PayAction` |
| `GET` | `/customer/documents` | JWT | `DocumentListAction` |
| `POST` | `/customer/documents` | JWT | `UploadAction` |
| `PATCH` | `/customer/documents/{id:[0-9]+}` | JWT | `DocumentRenameAction` |
| `GET` | `/customer/documents/{id:[0-9]+}/download` | JWT | `DownloadAction` |
| `POST` | `/customer/documents/{id:[0-9]+}/sign` | JWT | `SignAction` |
| `GET` | `/customer/messages` | JWT | `MessageListAction` |
| `POST` | `/customer/messages` | JWT | `MessageCreateAction` |
| `PATCH` | `/customer/messages/{id:[0-9]+}` | JWT | `MessageUpdateAction` |


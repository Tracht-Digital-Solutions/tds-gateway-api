# Architecture

## Backend

`php/src/MessagesModule.php` (namespace `Tds\Ext\Messages`) extends `AbstractModule`.
Data goes through the core's shared `PDO`; the repository lives in `php/src/Domain/`.

| Route | Purpose |
|---|---|
| `GET /messages/summary` | Unread count for the widget |
| `GET /messages` | The thread; marks the counterpart's messages read |
| `POST /messages` | Send a message |
| `PATCH /messages/{id:[0-9]+}` | Edit a message |

## Auth and ownership

- Auth is entirely the core `UserContext`: `messages:read` / `messages:write` (admins
  bypass), scoped by `activeCompanyId()`.
- `author_type` is `owner` for an admin, else `customer`.
- **Edit ownership:** admins edit any message. A customer edits only their own
  `author_type='customer'` rows in their company. A non-matching edit (`rowCount()==0`)
  returns **404**, so ids can't be probed.

## Data model

- Table `messages_message`; migration `CreateMessagesMessage` (`20260722000001`).
- **No customer/project foreign keys.** Those entities live in another domain, so
  `customer_id` / `project_id` are loose unsigned references. `customer_id` is the JWT's
  active company id (null = admin all-company view).

## Frontend

- `src/index.ts` — the manifest: nav entry, `/messages` route, `messages-unread` widget.
- `pages/Index.astro` renders `MessageThread`; `widgets/` and `islands/` hold the rest.
- The thread is an `AnimatedList` from `tds-shared/motion/react` (peer `>=0.38.7`), so a
  sent or arriving message slides in.

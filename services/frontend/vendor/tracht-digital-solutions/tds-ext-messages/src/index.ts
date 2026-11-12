import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Messages extension — the customer↔owner conversation thread, ported from
 * tds-customer-api (message table) onto the frontend platform. Customers read +
 * write messages in the portal; the owner (admin) replies. Data lives in this
 * extension's own `messages_message` table; auth (RBAC + company scoping) comes
 * from the core UserContext.
 *
 * `messages:read`/`messages:write` are the portal permissions; admins bypass.
 */
export default defineExtension({
  id: "messages",
  name: "Nachrichten",
  version: "0.1.0",
  permissions: [
    { id: "messages:read", label: "Nachrichten ansehen", group: "messages" },
    { id: "messages:write", label: "Nachrichten schreiben", group: "messages" },
  ],
  nav: [
    {
      id: "messages",
      label: "Nachrichten",
      href: "/messages",
      icon: "message-square",
      group: "support",
      order: 20,
      permission: "messages:read",
    },
  ],
  widgets: [
    {
      id: "messages-unread",
      title: "Neue Nachrichten",
      island: "@tracht-digital-solutions/tds-ext-messages/widgets/Widget.astro",
      size: "sm",
      permission: "messages:read",
      dataEndpoint: "/messages/summary",
      order: 20,
    },
  ],
  routes: [
    {
      pattern: "/messages",
      entrypoint: "@tracht-digital-solutions/tds-ext-messages/pages/Index.astro",
      permission: "messages:read",
    },
  ],
  i18n: {
    de: { "messages.title": "Nachrichten", "messages.unread": "Neue Nachrichten" },
    en: { "messages.title": "Messages", "messages.unread": "Unread messages" },
  },
});

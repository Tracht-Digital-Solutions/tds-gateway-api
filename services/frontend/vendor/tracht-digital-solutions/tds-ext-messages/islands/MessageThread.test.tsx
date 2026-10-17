// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { primeRuntimeConfig } from "@tracht-digital-solutions/tds-shared/api";
import { cleanup, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import MessageThread from "./MessageThread";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

/**
 * The portal message thread between a customer and the owner.
 *
 * The load-bearing assertion is **attribution**: every message is labelled
 * "Julian" (owner) or "Sie" (customer) and carries an `author_type` class.
 * Checking only that both labels EXIST passes when they are swapped — which
 * would show a customer their own words as if the owner had written them. So
 * each label is matched against its own message.
 *
 * A 403 is its own state (no access), not an error; a 401 is deliberately left
 * to the host's auth gate and is NOT special-cased here.
 */

interface Reply {
  status: number;
  body: unknown;
}
type Handler = (url: string, init?: RequestInit) => Reply | undefined;

let calls: Array<{ url: string; method: string; body: unknown }> = [];
let handlers: Handler[] = [];


/**
 * Path + query of a request. The island calls an ABSOLUTE URL now (via
 * `apiFetch`); a relative one would hit the product's own static host and come
 * back as SPA-fallback HTML with a 200. Matching on the path keeps the route
 * matchers below anchored.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");

function respond(match: RegExp, body: unknown, status = 200, method?: string) {
  handlers.unshift((url, init) => {
    if (!match.test(pathOf(url))) return undefined;
    if (method && (init?.method ?? "GET") !== method) return undefined;
    return { status, body };
  });
}

const FROM_CUSTOMER = {
  id: 1,
  customer_id: 3,
  project_id: null,
  author_type: "customer" as const,
  body: "Wann geht die Seite live?",
  created_at: "2026-07-20 09:00:00",
  read_at: null,
  edited_at: null,
};
const FROM_OWNER = {
  ...FROM_CUSTOMER,
  id: 2,
  author_type: "owner" as const,
  body: "Nächste Woche Dienstag.",
  created_at: "2026-07-20 10:30:00",
};

/** Outcomes are toasts now — collected off the `tds:toast` bus. */
let toasts: Array<{ variant: string; message: string }> = [];
const collectToast = (e: Event) => {
  toasts.push((e as CustomEvent<{ variant: string; message: string }>).detail);
};

beforeEach(() => {
  toasts = [];
  window.addEventListener(TOAST_EVENT, collectToast);
  calls = [];
  // jsdom has no scrollIntoView; the thread calls it on every render.
  (Element.prototype as unknown as { scrollIntoView: () => void }).scrollIntoView = vi.fn();
  handlers = [() => ({ status: 200, body: {} })];
  respond(/^\/messages$/, { messages: [] });
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      const reply = handlers.map((h) => h(url, init)).find((r) => r !== undefined)!;
      return { ok: reply.status < 300, status: reply.status, json: async () => reply.body } as Response;
    }),
  );
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, collectToast);
  cleanup();
});

const user = () => userEvent.setup({ delay: null });
const sent = (method: string, match: RegExp) => calls.filter((c) => c.method === method && match.test(pathOf(c.url)));

async function open(messages: unknown[] = []) {
  respond(/^\/messages$/, { messages }, 200, "GET");
  render(<MessageThread />);
  const u = user();
  await waitFor(() => expect(calls.length).toBeGreaterThan(0));
  return u;
}

const item = (text: string) => screen.getAllByRole("listitem").find((li) => li.textContent!.includes(text))!;
const composeBox = () => screen.getByPlaceholderText("Nachricht schreiben …");

// apiFetch consults the host-side runtime config (/tds-runtime.json) before it
// resolves a URL, so without this the first entry in fetch.mock.calls is that
// probe rather than the endpoint under test. The panel products never ship the
// file — they render <meta name="tds-api-base"> instead — so "absent" is also
// what actually happens in production.
beforeEach(() => primeRuntimeConfig(null));

describe("loading", () => {
  it("reads the thread with credentials", async () => {
    await open();
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(pathOf(fetchMock.mock.calls[0]![0] as string)).toBe("/messages");
    // Absolute, on the API host. Every other assertion here matches the PATH,
    // which a relative fetch satisfies too — so this is the one that fails if
    // the call ever goes back to the product's own origin (whose SPA fallback
    // answers 200 + HTML and turns into a silent empty state).
    expect(String(fetchMock.mock.calls[0]![0]).startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows a loading line until the thread arrives", () => {
    render(<MessageThread />);
    expect(screen.getByLabelText("Wird geladen")).toBeTruthy();
  });

  it("says so when the thread is empty", async () => {
    await open();
    expect(await screen.findByText("Noch keine Nachrichten.")).toBeTruthy();
  });

  it("still offers the compose box on an empty thread", async () => {
    // Otherwise a customer with no history could never start one.
    await open();
    await screen.findByText("Noch keine Nachrichten.");
    expect(composeBox()).toBeTruthy();
  });

  it("treats a 403 as NO ACCESS, not as a failure", async () => {
    respond(/^\/messages$/, {}, 403, "GET");
    render(<MessageThread />);
    expect(await screen.findByText("Kein Zugriff auf Nachrichten.")).toBeTruthy();
    expect(screen.queryByText(/konnten nicht geladen werden/)).toBeNull();
  });

  it("offers no compose box without access", async () => {
    respond(/^\/messages$/, {}, 403, "GET");
    render(<MessageThread />);
    await screen.findByText("Kein Zugriff auf Nachrichten.");
    expect(screen.queryByPlaceholderText("Nachricht schreiben …")).toBeNull();
  });

  it("leaves a 401 to the host auth gate rather than claiming no access", async () => {
    // The pre-paint gate owns the logged-out case; showing "Kein Zugriff"
    // here would tell an expired user they lack a permission they have.
    respond(/^\/messages$/, {}, 401, "GET");
    render(<MessageThread />);
    expect(await screen.findByText("Nachrichten konnten nicht geladen werden.")).toBeTruthy();
    expect(screen.queryByText("Kein Zugriff auf Nachrichten.")).toBeNull();
  });

  it("reports any other failure as an error", async () => {
    respond(/^\/messages$/, {}, 500, "GET");
    render(<MessageThread />);
    expect(await screen.findByText("Nachrichten konnten nicht geladen werden.")).toBeTruthy();
  });

  it("does NOT render messages carried by a non-OK response", async () => {
    respond(/^\/messages$/, { messages: [FROM_OWNER] }, 500, "GET");
    render(<MessageThread />);
    await screen.findByText("Nachrichten konnten nicht geladen werden.");
    expect(screen.queryByText("Nächste Woche Dienstag.")).toBeNull();
  });

  it("reports a rejected request rather than hanging on the loading line", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => { throw new TypeError("offline"); }));
    render(<MessageThread />);
    expect(await screen.findByText("Nachrichten konnten nicht geladen werden.")).toBeTruthy();
  });

  it("tolerates a response with no messages field", async () => {
    respond(/^\/messages$/, {}, 200, "GET");
    render(<MessageThread />);
    expect(await screen.findByText("Noch keine Nachrichten.")).toBeTruthy();
  });
});

describe("attribution", () => {
  it("labels each message with the RIGHT side of the conversation", async () => {
    // Asserting only that both labels exist passes when they are swapped —
    // which would show a customer their own words as the owner's.
    await open([FROM_CUSTOMER, FROM_OWNER]);
    await screen.findByText("Wann geht die Seite live?");
    expect(within(item("Wann geht die Seite live?")).getByText("Sie")).toBeTruthy();
    expect(within(item("Nächste Woche Dienstag.")).getByText("Julian")).toBeTruthy();
  });

  it("marks each message with its author_type class", async () => {
    await open([FROM_CUSTOMER, FROM_OWNER]);
    await screen.findByText("Wann geht die Seite live?");
    // The sides come from the shared thread primitive now — this is the
    // CUSTOMER's view, so the customer is `--own` and the owner `--other`. The
    // old `message--customer`/`--owner` classes matched no rule at all, which
    // is why they were replaced; this assertion had gone stale with them.
    expect(item("Wann geht die Seite live?").className).toContain("tds-thread__item--own");
    expect(item("Nächste Woche Dienstag.").className).toContain("tds-thread__item--other");
  });

  it("renders the timestamp in German date-and-time form", async () => {
    await open([FROM_CUSTOMER]);
    expect(await screen.findByText("20.07.2026, 09:00")).toBeTruthy();
  });

  it("keeps the raw timestamp machine-readable", async () => {
    await open([FROM_CUSTOMER]);
    await screen.findByText("Wann geht die Seite live?");
    expect(document.querySelector("time")!.getAttribute("datetime")).toBe("2026-07-20 09:00:00");
  });

  it("falls back to the raw value for an unparsable timestamp", async () => {
    // Better a raw string than "Invalid Date" in a customer's thread.
    await open([{ ...FROM_CUSTOMER, created_at: "irgendwann" }]);
    expect(await screen.findByText("irgendwann")).toBeTruthy();
  });

  it("flags an edited message", async () => {
    await open([{ ...FROM_OWNER, edited_at: "2026-07-20 11:00:00" }]);
    expect(await screen.findByText("(bearbeitet)")).toBeTruthy();
  });

  it("does not flag an unedited message", async () => {
    await open([FROM_OWNER]);
    await screen.findByText("Nächste Woche Dienstag.");
    expect(screen.queryByText("(bearbeitet)")).toBeNull();
  });

  it("preserves line breaks as separate paragraphs", async () => {
    // The body is rendered as text, never as HTML — a single block would
    // collapse a customer's formatting.
    await open([{ ...FROM_CUSTOMER, body: "Erste Zeile\nZweite Zeile" }]);
    await screen.findByText("Erste Zeile");
    expect(within(item("Erste Zeile")).getByText("Zweite Zeile")).toBeTruthy();
  });

  it("renders markup as inert text, never as HTML", async () => {
    await open([{ ...FROM_CUSTOMER, body: "<img src=x onerror=alert(1)>" }]);
    expect(await screen.findByText("<img src=x onerror=alert(1)>")).toBeTruthy();
    expect(document.querySelector("img")).toBeNull();
  });
});

describe("sending a message", () => {
  const posts = () => sent("POST", /^\/messages$/);

  it("keeps Senden disabled until something is typed", async () => {
    await open();
    expect((screen.getByRole("button", { name: "Senden" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("keeps Senden disabled for whitespace only", async () => {
    const u = await open();
    await u.type(composeBox(), "   ");
    expect((screen.getByRole("button", { name: "Senden" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("posts the trimmed body", async () => {
    const u = await open();
    await u.type(composeBox(), "  Kurze Rückfrage.  ");
    await u.click(screen.getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(posts()).toHaveLength(1));
    expect(posts()[0]!.body).toEqual({ body: "Kurze Rückfrage." });
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === "POST")!;
    expect((call[1] as RequestInit).headers).toMatchObject({ "Content-Type": "application/json" });
  });

  it("clears the box and re-reads the thread after sending", async () => {
    const u = await open();
    await u.type(composeBox(), "Kurze Rückfrage.");
    await u.click(screen.getByRole("button", { name: "Senden" }));
    await waitFor(() => expect((composeBox() as HTMLTextAreaElement).value).toBe(""));
    await waitFor(() => expect(sent("GET", /^\/messages$/)).toHaveLength(2));
  });

  it("KEEPS the typed message when sending fails", async () => {
    // Losing a customer's typed message is not recoverable from the UI.
    respond(/^\/messages$/, { error: "nope" }, 500, "POST");
    const u = await open();
    await u.type(composeBox(), "Kurze Rückfrage.");
    await u.click(screen.getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("Nachricht konnte nicht gesendet werden"))).toBe(true));
    expect((composeBox() as HTMLTextAreaElement).value).toBe("Kurze Rückfrage.");
  });

  it("does not re-read the thread after a failed send", async () => {
    respond(/^\/messages$/, { error: "nope" }, 500, "POST");
    const u = await open();
    await u.type(composeBox(), "Kurze Rückfrage.");
    await u.click(screen.getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("Nachricht konnte nicht gesendet werden"))).toBe(true));
    expect(sent("GET", /^\/messages$/)).toHaveLength(1);
  });

  it("re-enables the compose button afterwards", async () => {
    const u = await open();
    await u.type(composeBox(), "Kurze Rückfrage.");
    await u.click(screen.getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(posts()).toHaveLength(1));
    await u.type(composeBox(), "Noch eine.");
    expect((screen.getByRole("button", { name: "Senden" }) as HTMLButtonElement).disabled).toBe(false);
  });

  it("caps the compose box so a paste cannot exceed the column", async () => {
    await open();
    expect(composeBox().getAttribute("maxlength")).toBe("10000");
  });
});

describe("editing a message", () => {
  async function startEdit(u: ReturnType<typeof user>, text = "Nächste Woche Dienstag.") {
    await screen.findByText(text);
    await u.click(within(item(text)).getByRole("button", { name: "Bearbeiten" }));
    return screen.getByDisplayValue(text);
  }

  it("prefills the editor with the current body", async () => {
    const u = await open([FROM_OWNER]);
    expect(await startEdit(u)).toBeTruthy();
  });

  it("PATCHes the message it opened", async () => {
    // Edits the SECOND message on purpose: with the first, "the one I opened"
    // and "the first in the list" are the same id.
    const u = await open([FROM_CUSTOMER, FROM_OWNER]);
    const box = await startEdit(u);
    await box.focus();
    const uu = user();
    await uu.clear(box);
    await uu.type(box, "Nächste Woche Mittwoch.");
    await uu.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("PATCH", /^\/messages\/2$/)).toHaveLength(1));
    expect(sent("PATCH", /^\/messages\/2$/)[0]!.body).toEqual({ body: "Nächste Woche Mittwoch." });
    expect(sent("PATCH", /^\/messages\/1$/)).toHaveLength(0);
  });

  it("trims the edited body", async () => {
    const u = await open([FROM_OWNER]);
    const box = await startEdit(u);
    await u.clear(box);
    await u.type(box, "  Neu.  ");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("PATCH", /^\/messages\/2$/)).toHaveLength(1));
    expect(sent("PATCH", /^\/messages\/2$/)[0]!.body).toEqual({ body: "Neu." });
  });

  it("REFUSES to blank a message out", async () => {
    // Deleting content through the edit box would leave an empty bubble in a
    // customer's thread with no way to recover the text.
    const u = await open([FROM_OWNER]);
    const box = await startEdit(u);
    await u.clear(box);
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(sent("PATCH", /messages/)).toHaveLength(0);
  });

  it("refuses a whitespace-only body", async () => {
    const u = await open([FROM_OWNER]);
    const box = await startEdit(u);
    await u.clear(box);
    await u.type(box, "   ");
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    expect(sent("PATCH", /messages/)).toHaveLength(0);
  });

  it("closes the editor and reloads on success", async () => {
    const u = await open([FROM_OWNER]);
    await startEdit(u);
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("GET", /^\/messages$/)).toHaveLength(2));
    expect(screen.queryByRole("button", { name: "Speichern" })).toBeNull();
  });

  it("KEEPS the editor open and reports a failed edit", async () => {
    respond(/^\/messages\/2$/, { error: "nope" }, 403, "PATCH");
    const u = await open([FROM_OWNER]);
    await startEdit(u);
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(toasts.some((t) => t.variant === "danger" && t.message.includes("Änderung konnte nicht gespeichert werden"))).toBe(true));
    expect(screen.getByRole("button", { name: "Speichern" })).toBeTruthy();
    expect(sent("GET", /^\/messages$/)).toHaveLength(1);
  });

  it("abandons an edit without sending anything", async () => {
    const u = await open([FROM_OWNER]);
    await startEdit(u);
    await u.click(screen.getByRole("button", { name: "Abbrechen" }));
    expect(screen.queryByRole("button", { name: "Speichern" })).toBeNull();
    expect(sent("PATCH", /messages/)).toHaveLength(0);
  });

  it("edits one message at a time", async () => {
    const u = await open([FROM_CUSTOMER, FROM_OWNER]);
    await startEdit(u);
    expect(screen.getAllByRole("button", { name: "Speichern" })).toHaveLength(1);
    expect(screen.getByText("Wann geht die Seite live?")).toBeTruthy();
  });

  it("re-enables the editor buttons afterwards", async () => {
    const u = await open([FROM_OWNER]);
    await startEdit(u);
    await u.click(screen.getByRole("button", { name: "Speichern" }));
    await waitFor(() => expect(sent("PATCH", /^\/messages\/2$/)).toHaveLength(1));
    await startEdit(u);
    expect((screen.getByRole("button", { name: "Speichern" }) as HTMLButtonElement).disabled).toBe(false);
  });
});

import { useEffect, useRef, useState } from "react";
import { Alert, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch, sendJson } from "@tracht-digital-solutions/tds-shared/api";
import { formatDateTime } from "@tracht-digital-solutions/tds-shared/format";
import { AnimatedItem, AnimatedList } from "@tracht-digital-solutions/tds-shared/motion/react";

interface Message {
  id: number;
  customer_id: number | null;
  project_id: number | null;
  author_type: "customer" | "owner";
  body: string;
  created_at: string;
  read_at: string | null;
  edited_at: string | null;
}

/**
 * Portal message thread (ported from tds-customer-legacy-frontend Messages.tsx).
 * Alternating quoted blocks: owner messages get a primary-coloured left rule,
 * customer messages a hairline frame. Compose + inline edit (own/admin) reuse
 * the same backend rules; a 403 renders the no-access state, a 401 is left to
 * the host auth gate. Relative fetches with credentials.
 */
export default function MessageThread() {
  const [messages, setMessages] = useState<Message[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editDraft, setEditDraft] = useState("");
  const [editSaving, setEditSaving] = useState(false);
  const endRef = useRef<HTMLDivElement | null>(null);

  const load = () =>
    apiFetch("/messages")
      .then((r) => {
        if (r.status === 403) {
          setForbidden(true);
          return { messages: [] };
        }
        if (!r.ok) throw new Error(String(r.status));
        return r.json();
      })
      .then((d) => setMessages(d.messages ?? []))
      .catch(() => setError("Nachrichten konnten nicht geladen werden."));

  useEffect(() => {
    void load();
  }, []);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [messages]);

  async function send(e: React.SubmitEvent) {
    e.preventDefault();
    const text = draft.trim();
    if (!text || sending) return;
    setSending(true);
    try {
      const r = await sendJson("/messages", "POST", { body: text });
      if (!r.ok) throw new Error(String(r.status));
      setDraft("");
      await load();
    } catch {
      toast.danger("Nachricht konnte nicht gesendet werden.");
    } finally {
      setSending(false);
    }
  }

  async function saveEdit(id: number) {
    const text = editDraft.trim();
    if (!text || editSaving) return;
    setEditSaving(true);
    try {
      const r = await sendJson(`/messages/${id}`, "PATCH", { body: text });
      if (!r.ok) throw new Error(String(r.status));
      setEditingId(null);
      await load();
    } catch {
      toast.danger("Änderung konnte nicht gespeichert werden.");
    } finally {
      setEditSaving(false);
    }
  }

  if (forbidden) {
    return <p className="marginalia">Kein Zugriff auf Nachrichten.</p>;
  }
  if (error && messages === null) {
    return <Alert>{error}</Alert>;
  }
  if (messages === null) {
    return <p><Spinner /></p>;
  }

  return (
    <div className="message-thread">
      <Alert>{error}</Alert>
      {messages.length === 0 && <p className="marginalia">Noch keine Nachrichten.</p>}
      {/* Shared thread primitive. The side is mapped EXPLICITLY: the old code
          wrote `message message--${author_type}` and neither `.message` nor any
          `message--*` rule existed anywhere, so every bubble rendered unstyled.
          `--own` right-aligns, `--other` left-aligns; the customer is the reader
          here, matching the author label below ("Sie" vs "Julian"). */}
      <AnimatedList as="ol" className="tds-thread">
        {messages.map((m) => (
          <AnimatedItem
            key={m.id}
            className={`tds-thread__item ${
              m.author_type === "owner" ? "tds-thread__item--other" : "tds-thread__item--own"
            }`}
          >
            <header className="tds-row marginalia">
              <span className="tds-thread__author">{m.author_type === "owner" ? "Julian" : "Sie"}</span>
              <time dateTime={m.created_at}>{formatDateTime(m.created_at, { fallback: m.created_at })}</time>
              {m.edited_at && <span className="marginalia">(bearbeitet)</span>}
            </header>
            {editingId === m.id ? (
              <div className="tds-compose">
                <textarea
                  className="field-boxed"
                  value={editDraft}
                  onChange={(e) => setEditDraft(e.target.value)}
                  rows={3}
                  aria-label="Nachricht bearbeiten"
                />
                <div className="tds-compose__actions">
                  <button
                    type="button"
                    className="btn btn-primary"
                    onClick={() => saveEdit(m.id)}
                    disabled={editSaving}
                    aria-busy={editSaving}
                  >
                    {editSaving ? <Spinner size="sm" /> : null}
                    Speichern
                  </button>
                  <button
                    type="button"
                    className="btn btn-ghost"
                    onClick={() => setEditingId(null)}
                    disabled={editSaving}
                  >
                    Abbrechen
                  </button>
                </div>
              </div>
            ) : (
              <div className="tds-stack">
                {m.body.split("\n").map((line, i) => (
                  <p key={i}>{line}</p>
                ))}
                <button
                  type="button"
                  className="btn btn-ghost"
                  onClick={() => {
                    setEditingId(m.id);
                    setEditDraft(m.body);
                  }}
                >
                  Bearbeiten
                </button>
              </div>
            )}
          </AnimatedItem>
        ))}
      </AnimatedList>
      <div ref={endRef} />
      <form className="tds-compose" onSubmit={send}>
        {/* A placeholder is not a name: it disappears on the first keystroke
            and many screen readers skip it, so the field had no accessible
            name at all. The visible context (the thread above) makes a
            visible label redundant; the name is spoken instead. */}
        <textarea
          className="field-boxed"
          aria-label="Nachricht"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          placeholder="Nachricht schreiben …"
          rows={3}
          maxLength={10000}
        />
        <button
          type="submit"
          className="btn btn-primary"
          disabled={sending || draft.trim() === ""}
          aria-busy={sending}
        >
          {sending ? <Spinner size="sm" /> : null}
          Senden
        </button>
      </form>
    </div>
  );
}

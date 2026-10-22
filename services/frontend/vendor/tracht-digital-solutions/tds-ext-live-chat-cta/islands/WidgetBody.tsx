import { SummaryMetric } from "@tracht-digital-solutions/tds-shared/components";

interface Summary {
  openChats?: number;
  newContacts?: number;
}

/** Live-chat widget body — open chats, with new contact requests underneath. */
export default function WidgetBody() {
  return (
    <SummaryMetric<Summary>
      endpoint="/live-chat-cta/summary"
      value={(d) => Number(d.openChats ?? 0)}
      caption={(d) => {
        const n = Number(d.newContacts ?? 0);
        return `${n} neue Kontaktanfrage${n === 1 ? "" : "n"}`;
      }}
    />
  );
}

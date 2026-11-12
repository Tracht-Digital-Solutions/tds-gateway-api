import { SummaryMetric } from "@tracht-digital-solutions/tds-shared/components";

/** "Neue Nachrichten" widget body — the unread count from `/messages/summary`. */
export default function UnreadMessagesCount() {
  return <SummaryMetric<{ unread?: number }> endpoint="/messages/summary" value={(d) => Number(d.unread ?? 0)} />;
}

import { useSyncExternalStore } from "react";
import { Btn, Logo } from "../preview/ds";
import type { MemberMessagesFlow } from "./memberMessages";
import { RichText } from "./richText";

/*
 * STAGE-08 round 18, owner decision D30 (2026-11-23): «Μηνύματα» in the member app.
 *   MessagesSection  the list below the member area (newest first, «Νέο» on unread ones); hidden when the server has
 *                    no messages route or the account is not an enabled Club member;
 *   MessageView      one message in place of the member screens: title, date, summary and the formatted body
 *                    (richText.tsx: an allowlist renderer, never HTML put into the page), with «Πίσω».
 * Opening a notification tap (#message=<id>) shows MessageView directly (CustomerApp).
 */

export const MESSAGES_COPY = {
  title: "Μηνύματα",
  loading: "Φόρτωση των μηνυμάτων…",
  empty: "Δεν υπάρχουν ακόμη μηνύματα από το κατάστημα.",
  error: "Τα μηνύματα δεν φορτώθηκαν.",
  retry: "Δοκιμάστε ξανά",
  unreadOne: "1 νέο",
  unreadMany: (n: number) => `${n} νέα`,
  badge: "Νέο",
  back: "Πίσω",
  viewLoading: "Φόρτωση του μηνύματος…",
  notFound: "Το μήνυμα δεν βρέθηκε",
  notFoundDetail: "Ίσως δεν απευθύνεται σε αυτόν τον λογαριασμό ή δεν είναι πια διαθέσιμο.",
  viewError: "Το μήνυμα δεν φορτώθηκε. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.",
} as const;

/** «23 Νοεμβρίου 2026», Athens time. */
export function messageDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString("el-GR", { timeZone: "Europe/Athens", day: "numeric", month: "long", year: "numeric" });
}

export function MessagesSection({ flow, onOpen }: { flow: MemberMessagesFlow; onOpen: (id: number) => void }) {
  const { list } = useSyncExternalStore(flow.subscribe, flow.getState);
  if (list.phase === "hidden") return null;
  const unread = list.phase === "ready" ? list.unread : 0;
  return (
    <section aria-labelledby="evc-messages-title" data-evc-messages={list.phase} className="bg-[var(--stone)] px-5 pt-8 pb-2 md:px-10">
      <div className="mx-auto max-w-[1080px] rounded-[18px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 py-5 md:px-7">
        <h2 id="evc-messages-title" className="t-title flex items-baseline gap-3 text-[var(--roast)]">
          {MESSAGES_COPY.title}
          {unread > 0 && <span className="t-small font-semibold text-[var(--bronze-deep)]">{unread === 1 ? MESSAGES_COPY.unreadOne : MESSAGES_COPY.unreadMany(unread)}</span>}
        </h2>
        {list.phase === "loading" && <p className="t-body mt-2 text-[var(--ink-2)]">{MESSAGES_COPY.loading}</p>}
        {list.phase === "error" && (
          <div className="mt-2">
            <p role="alert" className="t-body text-[var(--ink-2)]">
              {MESSAGES_COPY.error}
            </p>
            <button type="button" onClick={() => void flow.load()} className="press t-small mt-2 min-h-11 rounded-full px-3 font-semibold text-[var(--ink)] underline underline-offset-4">
              {MESSAGES_COPY.retry}
            </button>
          </div>
        )}
        {list.phase === "ready" && list.messages.length === 0 && <p className="t-body mt-2 text-[var(--ink-2)]">{MESSAGES_COPY.empty}</p>}
        {list.phase === "ready" && list.messages.length > 0 && (
          <ul className="m-0 mt-3 list-none p-0">
            {list.messages.map((m) => (
              <li key={m.id} className="border-b border-[var(--rule)] last:border-b-0">
                <button type="button" onClick={() => onOpen(m.id)} data-evc-message={m.id} className="press grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-4 py-3.5 text-left">
                  <span className="min-w-0">
                    <span className={m.unread ? "t-body block font-semibold text-[var(--ink)]" : "t-body block text-[var(--ink)]"}>{m.title}</span>
                    <span className="t-small block text-[var(--ink-2)]">{m.summary}</span>
                  </span>
                  <span className="t-small text-right text-[var(--ink-3)]">
                    {m.unread && <span className="block font-semibold text-[var(--bronze-deep)]">{MESSAGES_COPY.badge}</span>}
                    <span className="t-num block">{messageDate(m.sentAt)}</span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}

export function MessageView({ flow, origin, onBack }: { flow: MemberMessagesFlow; origin: string; onBack: () => void }) {
  const { view } = useSyncExternalStore(flow.subscribe, flow.getState);
  if (view.phase === "closed") return null;
  return (
    <div className="min-h-[calc(100svh-52px)] bg-[var(--milk)]" data-evc-message-view={view.phase}>
      <header className="border-b border-[var(--rule)] bg-[var(--paper)]">
        <div className="mx-auto flex max-w-[1080px] items-center justify-between gap-8 px-5 py-4 md:px-10 md:py-2.5">
          <Logo className="h-8" />
          <Btn tone="line" onClick={onBack} data-evc-action="message-back">
            {MESSAGES_COPY.back}
          </Btn>
        </div>
      </header>
      <main className="mx-auto w-full max-w-[720px] px-5 pt-8 pb-16 md:px-10 md:pt-12">
        {view.phase === "loading" && (
          <p className="t-lede text-[var(--ink-2)]" aria-busy="true">
            {MESSAGES_COPY.viewLoading}
          </p>
        )}
        {view.phase === "not_found" && (
          <>
            <h1 className="t-headline text-[var(--roast)]">{MESSAGES_COPY.notFound}</h1>
            <p className="t-lede mt-3 max-w-[42ch] text-[var(--ink-2)]">{MESSAGES_COPY.notFoundDetail}</p>
          </>
        )}
        {view.phase === "error" && (
          <>
            <p role="alert" className="t-lede text-[var(--ink-2)]">
              {MESSAGES_COPY.viewError}
            </p>
            <div className="mt-6">
              <Btn onClick={() => void flow.open(view.id)}>{MESSAGES_COPY.retry}</Btn>
            </div>
          </>
        )}
        {view.phase === "shown" && (
          <article>
            <p className="t-small t-num text-[var(--ink-3)]">{messageDate(view.message.sentAt)}</p>
            <h1 className="t-headline mt-2 text-[var(--roast)]">{view.message.title}</h1>
            <p className="t-lede mt-3 text-[var(--ink-2)]">{view.message.summary}</p>
            <div className="mt-8">
              <RichText html={view.message.html} origin={origin} />
            </div>
          </article>
        )}
      </main>
    </div>
  );
}

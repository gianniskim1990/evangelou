import { useEffect, useState, useSyncExternalStore } from "react";
import { COFFEES, type CoffeeCode } from "../preview/previewData";
import { CustomerJourney } from "./CustomerJourney";
import type { MemberSnapshot } from "./customerServer";
import { athensDate, athensTime, nextAthensMidnight } from "./membershipCalendar";
import { SCENARIOS } from "./scenarios";
import { createWorld, type World } from "./world";
import "./customerPreview.css";

/*
 * CUSTOMER-01 LOCAL-ONLY customer journey preview (page body).
 *
 * The tooling (scenario, simulated clock, what the shop does, what the
 * simulated server knows, open decisions) is deliberately OUTSIDE the
 * customer's own screens: the customer never confirms a payment or records a
 * coffee. Every scenario builds a fresh world; nothing leaves the page and
 * nothing is stored.
 */

const STATE_WORD: Record<MemberSnapshot["state"], string> = { active: "Ενεργή", pending: "Σε αναμονή πληρωμής", expired: "Ληγμένη", cancelled: "Ακυρωμένη" };

const longDate = (ms: number) =>
  new Date(ms).toLocaleString("el-GR", { timeZone: "Europe/Athens", weekday: "long", day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit", hourCycle: "h23" });

const OPEN_DECISIONS = [
  "D7: e-mail προαιρετικό ή υποχρεωτικό (διακόπτης στα «Εργαλεία»)",
  "Τρόπος σύνδεσης μέλους και παράδοσης της κάρτας QR (D8)",
  "Πραγματικοί τρόποι πληρωμής και ποιος επιβεβαιώνει μετρητά/μεταφορά (D6)",
  "Ακύρωση στη μέση της περιόδου: τι γίνεται με τις υπόλοιπες μέρες (D10)",
  "Επιστροφές χρημάτων και καφέδες που έχουν δοθεί (D3/D4)",
  "Ξένοι αριθμοί κινητού· τελικός κατάλογος καφέ (D3) και κόστος επιλογών (D-PRICING)",
  "Διάρκεια διατήρησης ιστορικού (D9)",
];

export function CustomerJourneyPreview() {
  const [world, setWorld] = useState<World>(() => createWorld(SCENARIOS[0]));
  // On a phone the panel starts closed, so the customer screens come first.
  const [panel, setPanel] = useState<"steps" | "tools" | null>(() => (window.matchMedia?.("(min-width: 720px)").matches ? "steps" : null));
  const [emailRequired, setEmailRequired] = useState(true);
  const [coffee, setCoffee] = useState<CoffeeCode>("freddo-espresso");
  const [outcome, setOutcome] = useState<{ ok: boolean; text: string } | null>(null);
  useSyncExternalStore(world.server.subscribe, world.server.getVersion);

  useEffect(() => {
    document.documentElement.classList.remove("dark");
  }, []);

  const snapshot = world.server.snapshot();
  const now = world.clock.now();

  const choose = (id: string) => {
    const scenario = SCENARIOS.find((s) => s.id === id) ?? SCENARIOS[0];
    setWorld(createWorld(scenario));
    setOutcome(null);
    setPanel(window.matchMedia?.("(min-width: 720px)").matches ? "steps" : null);
    window.history.replaceState(null, "", window.location.pathname);
    window.scrollTo?.({ top: 0 });
  };

  const moveClock = (to: number, label: string) => {
    world.clock.set(to);
    world.server.touch();
    setOutcome({ ok: true, text: `Ώρα προσομοίωσης: ${label}.` });
  };

  const paidEnd = snapshot?.paidUntil ?? null;
  const groups = [...new Set(SCENARIOS.map((s) => s.group))];

  return (
    <div className="evc-cj evc-ds" data-evc-preview="customer-journey-local">
      <div className="evc-cj__bar">
        <span className="evc-cj__tag">ΤΟΠΙΚΗ ΠΡΟΕΠΙΣΚΟΠΗΣΗ</span>
        <span className="evc-cj__claim">Φανταστικά μέλη, προσομοιωμένος διακομιστής. Καμία εγγραφή, πληρωμή ή εξαργύρωση· ο κωδικός QR είναι δείγμα.</span>
        <label htmlFor="evc-cj-scenario" className="sr-only">Σενάριο</label>
        <select id="evc-cj-scenario" className="evc-cj__control evc-cj__select" value={world.scenario.id} onChange={(e) => choose(e.target.value)}>
          {groups.map((g) => (
            <optgroup key={g} label={g}>
              {SCENARIOS.filter((s) => s.group === g).map((s) => (
                <option key={s.id} value={s.id}>{s.title}</option>
              ))}
            </optgroup>
          ))}
        </select>
        <button type="button" className="evc-cj__control" onClick={() => choose(world.scenario.id)}>Από την αρχή</button>
        <button type="button" className="evc-cj__control" aria-expanded={panel === "steps"} onClick={() => setPanel(panel === "steps" ? null : "steps")}>Οδηγίες</button>
        <button type="button" className="evc-cj__control" aria-expanded={panel === "tools"} onClick={() => setPanel(panel === "tools" ? null : "tools")}>Εργαλεία</button>
      </div>

      {panel !== null && (
        <div className="evc-cj__panel" role="region" aria-label={panel === "steps" ? "Οδηγίες σεναρίου" : "Εργαλεία προσομοίωσης"}>
          {panel === "steps" ? (
            <>
              <section>
                <h2>Τι να δοκιμάσετε</h2>
                <ol className="evc-cj__steps">
                  {world.scenario.steps.map((step) => (
                    <li key={step}>{step}</li>
                  ))}
                </ol>
                <p className="evc-cj__expect"><strong>Τι πρέπει να ισχύει:</strong> {world.scenario.expect}</p>
              </section>
              <section>
                <h2>Ανοιχτές αποφάσεις (δεν προσομοιώνονται)</h2>
                <ul className="evc-cj__steps">
                  {OPEN_DECISIONS.map((d) => (
                    <li key={d}>{d}</li>
                  ))}
                </ul>
              </section>
            </>
          ) : (
            <>
              <section>
                <h2>Ώρα προσομοίωσης (Ελλάδα)</h2>
                <p className="evc-cj__clock" data-evc-cj="clock">{longDate(now)}</p>
                <div className="evc-cj__tools">
                  <button type="button" className="evc-cj__tool" onClick={() => moveClock(now + 3_600_000, "+1 ώρα")}>+1 ώρα</button>
                  <button type="button" className="evc-cj__tool" onClick={() => moveClock(now + 86_400_000, "+1 ημέρα")}>+1 ημέρα</button>
                  <button type="button" className="evc-cj__tool" onClick={() => moveClock(nextAthensMidnight(now) - 60_000, "1 λεπτό πριν τα μεσάνυχτα")}>Λίγο πριν τα μεσάνυχτα</button>
                  {paidEnd !== null && paidEnd > now && (
                    <>
                      <button type="button" className="evc-cj__tool" onClick={() => moveClock(paidEnd - 60_000, "1 λεπτό πριν τη λήξη")}>Λίγο πριν τη λήξη</button>
                      <button type="button" className="evc-cj__tool" onClick={() => moveClock(paidEnd, "η ακριβής στιγμή της λήξης")}>Στη λήξη</button>
                    </>
                  )}
                </div>
                <h2 className="evc-cj__gap">Τι κάνει το κατάστημα</h2>
                <div className="evc-cj__tools">
                  <button type="button" className="evc-cj__tool" disabled={!snapshot?.pendingPayment} onClick={() => setOutcome(world.server.confirmPayment())}>
                    Το κατάστημα επιβεβαιώνει την πληρωμή
                  </button>
                  <span className="evc-cj__inline">
                    <label htmlFor="evc-cj-coffee" className="sr-only">Καφές</label>
                    <select id="evc-cj-coffee" className="evc-cj__tool" value={coffee} onChange={(e) => setCoffee(e.target.value as CoffeeCode)}>
                      {COFFEES.map((c) => (
                        <option key={c.code} value={c.code}>{c.label}</option>
                      ))}
                    </select>
                    <button type="button" className="evc-cj__tool" disabled={!snapshot} onClick={() => setOutcome(world.server.recordCoffee(coffee))}>
                      Καταχώρηση καφέ από το ταμείο
                    </button>
                  </span>
                  <button type="button" className="evc-cj__tool" disabled={!snapshot || snapshot.state === "cancelled"} onClick={() => setOutcome(world.server.cancel())}>
                    Ακύρωση συνδρομής
                  </button>
                </div>
                <label className="evc-cj__switch">
                  <input type="checkbox" checked={emailRequired} onChange={(e) => setEmailRequired(e.target.checked)} />
                  E-mail υποχρεωτικό στην εγγραφή (ανοιχτή απόφαση D7)
                </label>
                <p className={outcome && !outcome.ok ? "evc-cj__outcome evc-cj__outcome--refused" : "evc-cj__outcome"} role="status" aria-live="polite">
                  {outcome?.text ?? ""}
                </p>
              </section>
              <section>
                <h2>Τι ξέρει ο διακομιστής (προσομοίωση)</h2>
                {snapshot ? (
                  <dl className="evc-cj__facts">
                    <dt>Μέλος</dt>
                    <dd>{snapshot.account.member.name}</dd>
                    <dt>Συνδρομή</dt>
                    <dd>{STATE_WORD[snapshot.state]}</dd>
                    <dt>Σήμερα (Ελλάδα)</dt>
                    <dd>{snapshot.today} · {snapshot.coffeeUsedToday ? "ο καφές καταχωρήθηκε" : snapshot.canTakeCoffeeToday ? "ο καφές είναι διαθέσιμος" : "δεν δικαιούται καφέ"}</dd>
                    <dt>Πληρωμένες περίοδοι</dt>
                    <dd>
                      {snapshot.periods.length === 0
                        ? "καμία"
                        : snapshot.periods.map((p) => (
                            <span key={p.start} className="evc-cj__period">
                              {athensDate(p.start)} {athensTime(p.start)} → {athensDate(p.end)} {athensTime(p.end)} ({p.renewal === "initial" ? "πρώτη" : p.renewal === "early" ? "πρόωρη ανανέωση" : "ανανέωση μετά τη λήξη"})
                            </span>
                          ))}
                    </dd>
                    <dt>Πληρωμή σε αναμονή</dt>
                    <dd>{snapshot.pendingPayment ? (snapshot.pendingPayment.kind === "initial" ? "πρώτη συνδρομή" : "ανανέωση") : "όχι"}</dd>
                    <dt>Newsletter</dt>
                    <dd>{snapshot.account.newsletter ? "ναι (ξεχωριστή συναίνεση)" : "όχι"}</dd>
                  </dl>
                ) : (
                  <p>Κανένας λογαριασμός: ο επισκέπτης δεν έχει εγγραφεί.</p>
                )}
              </section>
              <section>
                <h2>Ημερολόγιο διακομιστή</h2>
                <ol className="evc-cj__log">
                  {[...world.server.events()].reverse().slice(0, 8).map((e) => (
                    <li key={e.seq} className={e.tone === "refused" ? "evc-cj__refused" : undefined}>
                      <span>{athensTime(e.at)}</span> {e.text}
                    </li>
                  ))}
                  {world.server.events().length === 0 && <li>— κενό —</li>}
                </ol>
              </section>
            </>
          )}
        </div>
      )}

      <CustomerJourney key={world.id} server={world.server} now={world.clock.now} emailRequired={emailRequired} />
    </div>
  );
}

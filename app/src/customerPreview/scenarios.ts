import { historyPreparation } from "../preview/coffeeConfig";
import type { CustomerHistoryItem, CustomerView } from "../preview/customerView";
import { COFFEES, MEMBERS, addDays, type PreviewMember } from "../preview/previewData";
import type { Account, ClubRedemption, MemberSnapshot, ServerSeed } from "./customerServer";
import { addCalendarMonth, athensDate, athensInstant, athensTime, calendarDaysBetween, nextAthensMidnight, type PaidPeriod } from "./membershipCalendar";

/*
 * CUSTOMER-01 LOCAL-ONLY customer preview: deterministic scenarios and the
 * mapping from the simulated server's answer to what the approved customer
 * screens show. Fictional members from the approved UI-02 roster only.
 */

/** Every scenario starts on Monday 19 October 2026, 10:15 Athens time (simulated clock). */
export const SCENARIO_START = athensInstant({ y: 2026, m: 10, d: 19, hh: 10, mm: 15, ss: 0 });

const at = (y: number, m: number, d: number, hh: number, mm: number) => athensInstant({ y, m, d, hh, mm, ss: 0 });

function period(start: number, renewal: PaidPeriod["renewal"]): PaidPeriod {
  return { start, end: addCalendarMonth(start), renewal };
}

const TIMES = ["07:54", "08:12", "08:47", "09:05", "09:31", "10:18", "11:02", "12:40", "13:15", "16:22", "17:48"];

/** Deterministic past coffees: only on days (and at times) when a confirmed paid period was running, at most one per Athens day. */
export function syntheticHistory(member: PreviewMember, periods: PaidPeriod[], now: number, days = 21): ClubRedemption[] {
  const today = athensDate(now);
  const n = Number(member.id.replace(/\D/g, "")) || 0;
  const out: ClubRedemption[] = [];
  for (let d = 1; d <= days; d++) {
    const date = addDays(today, -d);
    if ((d * 7 + n * 3) % 5 >= 3) continue;
    const time = TIMES[(d + n * 2) % TIMES.length];
    const [hh, mm] = time.split(":").map(Number);
    const [y, mo, da] = date.split("-").map(Number);
    const instant = athensInstant({ y, m: mo, d: da, hh, mm, ss: 0 });
    if (!periods.some((p) => p.start <= instant && instant < p.end)) continue;
    const coffee = (d + n) % 4 === 0 ? COFFEES[(d + n) % COFFEES.length].code : member.favourite;
    out.push({ date, time, coffee, memberId: member.id, preparation: historyPreparation(member, coffee, date) });
  }
  return out;
}

function account(member: PreviewMember, periods: PaidPeriod[], registeredAt: number): Account {
  const since = periods.length > 0 ? athensDate(periods[0].start) : athensDate(registeredAt);
  return { member: { ...member, memberSince: since }, emailGiven: true, termsAcceptedAt: registeredAt, newsletter: member.newsletter, registeredAt };
}

function memberSeed(member: PreviewMember, periods: PaidPeriod[], extra: Partial<ServerSeed> = {}): ServerSeed {
  const registeredAt = periods[0]?.start ?? SCENARIO_START;
  return { account: account(member, periods, registeredAt), periods, redemptions: syntheticHistory(member, periods, SCENARIO_START), ...extra };
}

const byId = (id: string) => MEMBERS.find((m) => m.id === id)!;

export type Scenario = {
  id: string;
  group: string;
  title: string;
  seed: () => ServerSeed;
  steps: string[];
  expect: string;
};

export const SCENARIOS: Scenario[] = [
  {
    id: "visitor",
    group: "Νέος πελάτης",
    title: "Εγγραφή από την αρχή",
    seed: () => ({ account: null }),
    steps: [
      "«Γίνετε μέλος» → πατήστε «Συνέχεια» με κενά πεδία για να δείτε τα μηνύματα",
      "Συμπληρώστε ονοματεπώνυμο, ελληνικό κινητό (69…) και e-mail → «Συνέχεια»",
      "Αποδεχτείτε τους όρους· το newsletter είναι προαιρετικό → «Συνέχεια»",
      "«Πληρωμή 20 €» → δείτε ότι δεν έγινε πληρωμή → «Στον λογαριασμό μου»",
      "Εργαλεία → «Το κατάστημα επιβεβαιώνει την πληρωμή» → η κάρτα ενεργοποιείται",
    ],
    expect: "Καμία οθόνη δεν λέει ότι έγινε πληρωμή ή εγγραφή. Μέχρι την επιβεβαίωση από το κατάστημα η συνδρομή είναι «σε αναμονή πληρωμής» και δεν δίνει καφέ. Το newsletter δεν επηρεάζει τη συνδρομή.",
  },
  {
    id: "pending",
    group: "Μέλος",
    title: "Σε αναμονή πληρωμής",
    seed: () => ({ ...memberSeed(byId("m04"), []), account: account(byId("m04"), [], at(2026, 10, 18, 19, 40)), pendingPayment: { kind: "initial", createdAt: at(2026, 10, 18, 19, 40) } }),
    steps: ["Δείτε την αρχική και την κάρτα", "Εργαλεία → «Καταχώρηση καφέ από το ταμείο» → απορρίπτεται", "Εργαλεία → «Το κατάστημα επιβεβαιώνει την πληρωμή»"],
    expect: "Πριν την επιβεβαίωση δεν δίνεται καφές· μετά, ο μήνας μετρά από τη στιγμή της επιβεβαίωσης και λήγει την ίδια ώρα Ελλάδας τον επόμενο μήνα.",
  },
  {
    id: "active",
    group: "Μέλος",
    title: "Ενεργό μέλος, ο καφές της ημέρας διαθέσιμος",
    seed: () => memberSeed(byId("m01"), [period(at(2026, 9, 2, 18, 5), "initial"), period(at(2026, 10, 8, 10, 42), "late")]),
    steps: ["Η εφαρμογή ανοίγει κατευθείαν στην κάρτα", "Εργαλεία → «Καταχώρηση καφέ από το ταμείο»", "Δείτε «Σήμερα» και «Ιστορικό»· δοκιμάστε δεύτερο καφέ"],
    expect: "Ο πρώτος καφές της ημέρας καταχωρείται· ο δεύτερος την ίδια ημέρα Ελλάδας απορρίπτεται. Μετά τα μεσάνυχτα ώρας Ελλάδας ο καφές είναι ξανά διαθέσιμος.",
  },
  {
    id: "used",
    group: "Μέλος",
    title: "Ο σημερινός καφές έχει ήδη καταχωρηθεί",
    seed: () => {
      const member = byId("m01");
      const seed = memberSeed(member, [period(at(2026, 9, 2, 18, 5), "initial"), period(at(2026, 10, 8, 10, 42), "late")]);
      const date = athensDate(SCENARIO_START);
      return { ...seed, redemptions: [...(seed.redemptions ?? []), { date, time: "08:47", coffee: member.favourite, memberId: member.id, preparation: historyPreparation(member, member.favourite, date) }] };
    },
    steps: ["Δείτε την κάρτα: «Τον απολαύσατε σήμερα»", "Εργαλεία → «Λίγο πριν τα μεσάνυχτα» και μετά «+1 ώρα»"],
    expect: "Ο καφές επανέρχεται ακριβώς στα μεσάνυχτα ώρας Ελλάδας, όχι 24 ώρες μετά τον προηγούμενο.",
  },
  {
    id: "expiring",
    group: "Μέλος",
    title: "Λήγει αύριο: πρόωρη ανανέωση",
    seed: () => memberSeed(byId("m05"), [period(at(2026, 9, 20, 9, 0), "initial")]),
    steps: ["Λογαριασμός → «Ανανέωση τώρα»", "Δείτε ότι η τρέχουσα συνδρομή δεν αλλάζει όσο η πληρωμή περιμένει", "Εργαλεία → «Το κατάστημα επιβεβαιώνει την πληρωμή»"],
    expect: "Η νέα περίοδος ξεκινά από την τρέχουσα λήξη (20 Οκτωβρίου, 09:00) και λήγει 20 Νοεμβρίου, 09:00 ώρα Ελλάδας: καμία μέρα δεν χάνεται.",
  },
  {
    id: "expired",
    group: "Μέλος",
    title: "Ληγμένη συνδρομή",
    seed: () => memberSeed(byId("m03"), [period(at(2026, 8, 16, 19, 30), "initial"), period(at(2026, 9, 16, 19, 30), "early")]),
    steps: ["Δείτε την αρχική: «Η συνδρομή έληξε»", "«Ανανέωση 20 €» → σε αναμονή πληρωμής", "Εργαλεία → «Το κατάστημα επιβεβαιώνει την πληρωμή»"],
    expect: "Μετά τη λήξη δεν δίνεται καφές. Η ανανέωση μετά τη λήξη ξεκινά τη στιγμή της επιβεβαίωσης της πληρωμής, όχι τη στιγμή του αιτήματος.",
  },
  {
    id: "cancelled",
    group: "Μέλος",
    title: "Ακυρωμένη συνδρομή",
    seed: () => {
      const cancelledAt = at(2026, 10, 17, 16, 0);
      const seed = memberSeed(byId("m15"), [period(at(2026, 10, 5, 11, 20), "initial")]);
      // No coffee after the cancellation.
      return { ...seed, cancelledAt, redemptions: (seed.redemptions ?? []).filter((r) => r.date < athensDate(cancelledAt)) };
    },
    steps: ["Δείτε την αρχική, την κάρτα και τον λογαριασμό", "Εργαλεία → «Καταχώρηση καφέ από το ταμείο» → απορρίπτεται"],
    expect: "Η ακυρωμένη συνδρομή δεν δίνει καφέ. Τι γίνεται με το υπόλοιπο της πληρωμένης περιόδου είναι ανοιχτή απόφαση (D10).",
  },
];

/** D-USUAL, simplified for the preview: the most frequent coffee of the member's own records, only with at least 3 of them. */
export function derivedUsual(history: readonly ClubRedemption[]): CustomerView["usual"] {
  if (history.length < 3) return null;
  const counts = new Map<string, number>();
  for (const r of history) counts.set(r.coffee, (counts.get(r.coffee) ?? 0) + 1);
  let best: ClubRedemption["coffee"] | null = null;
  let bestCount = 0;
  for (const r of history) {
    const c = counts.get(r.coffee)!;
    if (c > bestCount) {
      best = r.coffee;
      bestCount = c;
    }
  }
  return best;
}

/** What the approved customer screens show for a server snapshot at `now`. */
export function toCustomerView(s: MemberSnapshot, now: number): CustomerView {
  const until = s.paidUntil;
  const history: CustomerHistoryItem[] = s.history.map((r) => ({ date: r.date, time: r.time, coffee: r.coffee, memberId: r.memberId, preparation: r.preparation }));
  return {
    member: s.account.member,
    state: s.state,
    coffeeUsedToday: s.coffeeUsedToday,
    today: s.today,
    paidUntil: until === null ? null : athensDate(until),
    paidUntilTime: until === null ? null : athensTime(until),
    daysLeft: until === null ? 0 : Math.max(0, calendarDaysBetween(s.today, athensDate(until))),
    visitsThisMonth: s.history.filter((r) => r.date.slice(0, 7) === s.today.slice(0, 7)).length,
    history,
    usual: derivedUsual(s.history),
    minutesToMidnight: Math.floor((nextAthensMidnight(now) - now) / 60_000),
    pendingRenewal: s.pendingPayment?.kind === "renewal",
  };
}

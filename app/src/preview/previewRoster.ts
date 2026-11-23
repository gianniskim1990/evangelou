import { COFFEES, addDays, type PreviewMember, type PreviewRedemption } from "./previewData";

/*
 * UI-01 DESIGN PREVIEW — the fictional roster (entirely synthetic) and its synthetic redemption history.
 * STAGE-08 round 17 (owner-found bug, staging 2026-11-23): moved unchanged out of previewData.ts so that the real
 * member app, which shares previewData.ts's rules and calendar helpers, never imports a fictional member
 * (tests/club/customerSampleGuard.test.ts and scripts/sample-guard.mjs check the import graph).
 */

/** Fictional members (design preview only). */
export const MEMBERS: PreviewMember[] = [
  { id: "m01", name: "Αλεξάνδρα Κωνσταντίνου", initials: "ΑΚ", phoneMasked: "69•• ••• •14", email: "alexandra.k@example.invalid", memberSince: "2026-09-02", state: "active", paidUntilOffsetDays: 19, redeemedToday: false, favourite: "freddo-espresso", newsletter: true, visitsThisMonth: 9 },
  { id: "m02", name: "Νικόλαος Βασιλείου", initials: "ΝΒ", phoneMasked: "69•• ••• •27", email: "nikos.v@example.invalid", memberSince: "2026-09-10", state: "active", paidUntilOffsetDays: 4, redeemedToday: true, favourite: "espresso", newsletter: false, visitsThisMonth: 11 },
  { id: "m03", name: "Δήμητρα Ηλιοπούλου", initials: "ΔΗ", phoneMasked: "69•• ••• •33", email: "dimitra.i@example.invalid", memberSince: "2026-08-21", state: "expired", paidUntilOffsetDays: -3, redeemedToday: false, favourite: "cappuccino", newsletter: true, visitsThisMonth: 4 },
  { id: "m04", name: "Γιάννης Ραφαηλίδης", initials: "ΓΡ", phoneMasked: "69•• ••• •48", email: "giannis.r@example.invalid", memberSince: "2026-10-11", state: "pending", paidUntilOffsetDays: 0, redeemedToday: false, favourite: "greek-coffee", newsletter: false, visitsThisMonth: 0 },
  { id: "m05", name: "Σοφία Λαμπράκη", initials: "ΣΛ", phoneMasked: "69•• ••• •52", email: "sofia.l@example.invalid", memberSince: "2026-09-25", state: "active", paidUntilOffsetDays: 12, redeemedToday: false, favourite: "freddo-cappuccino", newsletter: true, visitsThisMonth: 7 },
  { id: "m06", name: "Παναγιώτης Μαυρίδης", initials: "ΠΜ", phoneMasked: "69•• ••• •61", email: "panos.m@example.invalid", memberSince: "2026-09-04", state: "active", paidUntilOffsetDays: 1, redeemedToday: false, favourite: "filter", newsletter: false, visitsThisMonth: 13 },
  { id: "m07", name: "Ειρήνη Θεοδωρίδου", initials: "ΕΘ", phoneMasked: "69•• ••• •75", email: "eirini.t@example.invalid", memberSince: "2026-07-30", state: "expired", paidUntilOffsetDays: -16, redeemedToday: false, favourite: "freddo-espresso", newsletter: true, visitsThisMonth: 0 },
  { id: "m08", name: "Κωνσταντίνος Αλεξίου", initials: "ΚΑ", phoneMasked: "69•• ••• •89", email: "kostas.a@example.invalid", memberSince: "2026-10-02", state: "active", paidUntilOffsetDays: 20, redeemedToday: true, favourite: "cappuccino", newsletter: true, visitsThisMonth: 8 },
  { id: "m09", name: "Μαρία Παπαδάκη", initials: "ΜΠ", phoneMasked: "69•• ••• •03", email: "maria.p@example.invalid", memberSince: "2026-09-14", state: "active", paidUntilOffsetDays: 3, redeemedToday: true, favourite: "freddo-cappuccino", newsletter: true, visitsThisMonth: 10 },
  { id: "m10", name: "Στέλιος Καραγιάννης", initials: "ΣΚ", phoneMasked: "69•• ••• •18", email: "stelios.k@example.invalid", memberSince: "2026-09-18", state: "active", paidUntilOffsetDays: 7, redeemedToday: false, favourite: "greek-coffee", newsletter: false, visitsThisMonth: 6 },
  { id: "m11", name: "Ελένη Μιχαηλίδου", initials: "ΕΜ", phoneMasked: "69•• ••• •22", email: "eleni.m@example.invalid", memberSince: "2026-10-05", state: "active", paidUntilOffsetDays: 23, redeemedToday: true, favourite: "freddo-espresso", newsletter: true, visitsThisMonth: 7 },
  { id: "m12", name: "Χρήστος Δημητρίου", initials: "ΧΔ", phoneMasked: "69•• ••• •36", email: "christos.d@example.invalid", memberSince: "2026-09-29", state: "active", paidUntilOffsetDays: 16, redeemedToday: false, favourite: "espresso", newsletter: false, visitsThisMonth: 9 },
  { id: "m13", name: "Αθηνά Σταυροπούλου", initials: "ΑΣ", phoneMasked: "69•• ••• •40", email: "athina.s@example.invalid", memberSince: "2026-10-12", state: "pending", paidUntilOffsetDays: 0, redeemedToday: false, favourite: "cappuccino", newsletter: true, visitsThisMonth: 0 },
  { id: "m14", name: "Θοδωρής Αναστασίου", initials: "ΘΑ", phoneMasked: "69•• ••• •57", email: "thodoris.a@example.invalid", memberSince: "2026-09-08", state: "active", paidUntilOffsetDays: 2, redeemedToday: false, favourite: "freddo-espresso", newsletter: false, visitsThisMonth: 12 },
  { id: "m15", name: "Κατερίνα Γεωργίου", initials: "ΚΓ", phoneMasked: "69•• ••• •64", email: "katerina.g@example.invalid", memberSince: "2026-08-14", state: "expired", paidUntilOffsetDays: -8, redeemedToday: false, favourite: "filter", newsletter: true, visitsThisMonth: 2 },
  { id: "m16", name: "Μιχάλης Οικονόμου", initials: "ΜΟ", phoneMasked: "69•• ••• •70", email: "michalis.o@example.invalid", memberSince: "2026-09-21", state: "active", paidUntilOffsetDays: 10, redeemedToday: true, favourite: "freddo-espresso", newsletter: true, visitsThisMonth: 8 },
  { id: "m17", name: "Βασιλική Ζαφειρίου", initials: "ΒΖ", phoneMasked: "69•• ••• •83", email: "vasiliki.z@example.invalid", memberSince: "2026-10-01", state: "active", paidUntilOffsetDays: 18, redeemedToday: false, favourite: "freddo-cappuccino", newsletter: false, visitsThisMonth: 5 },
  { id: "m18", name: "Άγγελος Πετρίδης", initials: "ΑΠ", phoneMasked: "69•• ••• •95", email: "angelos.p@example.invalid", memberSince: "2026-09-27", state: "active", paidUntilOffsetDays: 14, redeemedToday: false, favourite: "espresso", newsletter: true, visitsThisMonth: 4 },
];

/** The member used for the customer-side screens. */
export const CUSTOMER = MEMBERS[0];

// ---- Redemption history (synthetic) ----------------------------------------------

const TIMES = ["07:54", "08:12", "08:47", "09:05", "09:31", "10:18", "11:02", "12:40", "13:15", "16:22", "17:48"];

/** Deterministic synthetic history for the last N Athens days (at most one per member per day). */
export function redemptionHistory(today: string, days = 21): PreviewRedemption[] {
  const out: PreviewRedemption[] = [];
  for (let d = 1; d <= days; d++) {
    const date = addDays(today, -d);
    MEMBERS.forEach((m, i) => {
      if (m.state === "pending") return;
      if (m.state === "expired" && d < -m.paidUntilOffsetDays) return;
      if (date < m.memberSince) return;
      // ~60% of weekdays, ~80% of Saturdays, ~40% of Sundays.
      const weekday = new Date(`${date}T12:00:00Z`).getUTCDay();
      if ((d * 7 + i * 3) % 5 >= (weekday === 0 ? 2 : weekday === 6 ? 4 : 3)) return;
      const coffee = (d + i) % 4 === 0 ? COFFEES[(d + i) % COFFEES.length].code : m.favourite;
      out.push({ date, time: TIMES[(d + i * 2) % TIMES.length], coffee, memberId: m.id });
    });
  }
  return out;
}

export function memberHistory(memberId: string, today: string): PreviewRedemption[] {
  return redemptionHistory(today).filter((r) => r.memberId === memberId);
}

/** Today's (synthetic) redemptions, consistent with each member's `redeemedToday`. */
export function todaysRedemptions(today: string): PreviewRedemption[] {
  return MEMBERS.filter((m) => m.redeemedToday).map((m, i) => ({ date: today, time: TIMES[i + 1], coffee: m.favourite, memberId: m.id }));
}

export function memberById(id: string): PreviewMember {
  return MEMBERS.find((m) => m.id === id) ?? MEMBERS[0];
}

/* UI-01 design preview: screen registry and /club-preview route parsing (pure). */

export const CUSTOMER_SCREENS = [
  { id: "welcome", label: "Καλωσόρισμα" },
  { id: "register", label: "Εγγραφή" },
  { id: "newsletter", label: "Newsletter & συναινέσεις" },
  { id: "offer", label: "Συνδρομή 20 €" },
  { id: "dashboard", label: "Αρχική μέλους" },
  { id: "card", label: "Κάρτα μέλους" },
  { id: "states", label: "Καταστάσεις συνδρομής" },
  { id: "today", label: "Σημερινός καφές" },
  { id: "history", label: "Ιστορικό" },
  { id: "profile", label: "Λογαριασμός" },
] as const;

export const STAFF_SCREENS = [
  { id: "dashboard", label: "Πίνακας ταμείου" },
  { id: "scan", label: "Σάρωση QR" },
  { id: "search", label: "Αναζήτηση τηλεφώνου" },
  { id: "member", label: "Μέλος & δικαίωμα" },
  { id: "coffee", label: "Επιλογή καφέ" },
  { id: "confirm", label: "Επιβεβαίωση" },
  { id: "blocked", label: "Ήδη εξαργυρώθηκε / Έληξε" },
  { id: "history", label: "Ιστορικό μέλους" },
] as const;

export const MANAGEMENT_SCREENS = [
  { id: "overview", label: "Επισκόπηση" },
  { id: "members", label: "Μέλη" },
  { id: "member", label: "Καρτέλα μέλους" },
  { id: "statuses", label: "Καταστάσεις συνδρομών" },
  { id: "activity", label: "Δραστηριότητα" },
  { id: "preferences", label: "Προτιμήσεις καφέ" },
  { id: "analytics", label: "Στατιστικά" },
  { id: "settings", label: "Ρυθμίσεις Club" },
] as const;

export type CustomerScreen = (typeof CUSTOMER_SCREENS)[number]["id"];
export type StaffScreen = (typeof STAFF_SCREENS)[number]["id"];
export type ManagementScreen = (typeof MANAGEMENT_SCREENS)[number]["id"];

export type Area = "customer" | "staff" | "management";

export const AREAS: { id: Area; label: string; icon: "user" | "coffee" | "chart"; screens: readonly { id: string; label: string }[] }[] = [
  { id: "customer", label: "Πελάτης", icon: "user", screens: CUSTOMER_SCREENS },
  { id: "staff", label: "Προσωπικό", icon: "coffee", screens: STAFF_SCREENS },
  { id: "management", label: "Διαχείριση", icon: "chart", screens: MANAGEMENT_SCREENS },
];

/** "/club-preview/staff/scan" -> {area:"staff", screen:"scan"}; unknown parts fall back to the first entry. */
export function parsePreviewPath(pathname: string): { area: Area; screen: string } {
  const [, , rawArea, rawScreen] = pathname.split("/");
  const area = AREAS.find((a) => a.id === rawArea) ?? AREAS[0];
  const screen = area.screens.find((s) => s.id === rawScreen)?.id ?? area.screens[0].id;
  return { area: area.id, screen };
}

export function previewPath(area: Area, screen: string): string {
  return `/club-preview/${area}/${screen}`;
}

export function cx(...c: (string | false | null | undefined)[]): string {
  return c.filter(Boolean).join(" ");
}

/** Deterministic QR-LOOKING module pattern (not an encoded QR code). */
export function sampleQrModules(seed: number, n = 25): [number, number][] {
  let s = seed * 9301 + 49297;
  const out: [number, number][] = [];
  const inFinder = (r: number, c: number) => {
    const box = (r0: number, c0: number) => r >= r0 && r < r0 + 7 && c >= c0 && c < c0 + 7;
    return box(0, 0) || box(0, n - 7) || box(n - 7, 0);
  };
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; c++) {
      if (inFinder(r, c)) continue;
      s = (s * 9301 + 49297) % 233280;
      if (s / 233280 > 0.52) out.push([r, c]);
    }
  }
  return out;
}

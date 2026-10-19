import type { FakeCustomerServer } from "./customerServer";

/* CUSTOMER-01 LOCAL-ONLY customer journey: screen ids, titles and where the app opens (pure). */

export type JourneyScreen = "welcome" | "register" | "consents" | "offer" | "payment" | "signin" | "dashboard" | "card" | "today" | "history" | "profile";

export const ACCOUNT_SCREENS: ReadonlySet<JourneyScreen> = new Set(["payment", "dashboard", "card", "today", "history", "profile"]);
export const ALL_SCREENS: readonly JourneyScreen[] = ["welcome", "register", "consents", "offer", "payment", "signin", "dashboard", "card", "today", "history", "profile"];

export const SCREEN_TITLES: Record<JourneyScreen, string> = {
  welcome: "Καλωσόρισμα",
  register: "Εγγραφή: στοιχεία",
  consents: "Εγγραφή: επιλογές",
  offer: "Συνδρομή",
  payment: "Πληρωμή",
  signin: "Σύνδεση μέλους",
  dashboard: "Αρχική μέλους",
  card: "Κάρτα μέλους",
  today: "Σημερινός καφές",
  history: "Ιστορικό",
  profile: "Λογαριασμός",
};

/** Where the app opens: an active member straight on the card, any other member on the dashboard, a visitor on the welcome page. */
export function startScreen(server: FakeCustomerServer): JourneyScreen {
  const s = server.snapshot();
  if (!s) return "welcome";
  return s.state === "active" ? "card" : "dashboard";
}

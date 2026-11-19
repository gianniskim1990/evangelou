import type { CustomerMembershipState, CustomerView } from "../preview/customerView";
import type { PreviewMember } from "../preview/previewData";
import type { MemberCoffees } from "./coffeesApi";
import type { Me, MembershipState } from "./customerApi";

/*
 * STAGE-01: what the approved customer screens show for a /me answer (pure).
 * Only facts the server returned are shown; everything the Club database
 * would know (today's coffee, history, visits, usual coffee) is marked
 * unavailable, never guessed. Times are Europe/Athens.
 *
 * Owner decision D23 (2026-11-19): with the member's own figures from
 * GET /me/coffees (memberCoffees.ts), today's coffee and the coffees of this
 * Athens month are real. They are used ONLY for the Athens day they belong
 * to: figures of another day (fetched before midnight, shown after it) are
 * unknown again («—») until the next answer. The coffee history itself is
 * not sent by the server, so it stays unavailable (historyData).
 */

const ATHENS = new Intl.DateTimeFormat("en-GB", { timeZone: "Europe/Athens", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23" });

function athensParts(ms: number): { date: string; time: string; minutes: number } {
  const p = ATHENS.formatToParts(new Date(ms));
  const get = (t: string) => p.find((x) => x.type === t)?.value ?? "";
  const hh = Number(get("hour"));
  const mm = Number(get("minute"));
  return { date: `${get("year")}-${get("month")}-${get("day")}`, time: `${get("hour")}:${get("minute")}`, minutes: hh * 60 + mm };
}

const STATE: Record<MembershipState, CustomerMembershipState> = {
  active: "active",
  expired: "expired",
  cancelled: "cancelled",
  pending_payment: "pending",
  none: "none",
  unverified: "unverified",
};

/** The approved screens' PreviewMember shape: only the account's own name and e-mail are real. */
function memberOf(me: Me): PreviewMember {
  const name = me.displayName.trim() || me.email;
  const initials = name
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0] ?? "")
    .join("")
    .toLocaleUpperCase("el-GR");
  return {
    id: "me",
    name,
    initials,
    phoneMasked: "",
    email: me.email,
    memberSince: "",
    state: me.state === "active" ? "active" : me.state === "pending_payment" ? "pending" : "expired",
    paidUntilOffsetDays: 0,
    redeemedToday: false,
    favourite: "espresso",
    newsletter: false,
    visitsThisMonth: 0,
  };
}

export function toLiveView(me: Me, now: number, coffees: MemberCoffees | null = null): CustomerView {
  const today = athensParts(now);
  const known = coffees !== null && coffees.today === today.date;
  const until = me.paidUntilUtc === null ? null : athensParts(Date.parse(me.paidUntilUtc));
  const daysLeft = until === null ? 0 : Math.max(0, Math.round((Date.parse(`${until.date}T12:00:00Z`) - Date.parse(`${today.date}T12:00:00Z`)) / 86_400_000));
  return {
    member: memberOf(me),
    state: STATE[me.state],
    coffeeUsedToday: known ? coffees.takenToday : false,
    today: today.date,
    paidUntil: until?.date ?? null,
    paidUntilTime: until?.time ?? null,
    daysLeft,
    visitsThisMonth: known ? coffees.thisMonth : 0,
    history: [],
    usual: null,
    minutesToMidnight: 24 * 60 - today.minutes,
    pendingRenewal: false,
    live: true,
    ...(known ? {} : { clubData: "unavailable" as const }),
    historyData: "unavailable",
  };
}

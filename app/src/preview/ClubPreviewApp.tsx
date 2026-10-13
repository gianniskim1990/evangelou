import { useEffect, useMemo, useState } from "react";
import { CustomerPreview } from "./CustomerPreview";
import { ManagementPreview } from "./ManagementPreview";
import { PREVIEW_MARKER, athensToday, type PreviewMembershipState } from "./previewData";
import { AREAS, cx, parsePreviewPath, previewPath, type Area, type CustomerScreen, type ManagementScreen, type StaffScreen } from "./screens";
import { StaffPreview } from "./StaffPreview";
import { Icon } from "./ui";

/**
 * UI-01 — Evangelou Club DESIGN PREVIEW (development only).
 *
 * Visual prototype with synthetic data. It performs no network requests and
 * imports no service, API, WordPress or payment code. Only compiled in when
 * __EVC_CLUB_PREVIEW__ is true (never for Vercel production builds).
 * Routes: /club-preview/<area>/<screen>.
 */

export function ClubPreviewApp() {
  const [route, setRoute] = useState(() => parsePreviewPath(window.location.pathname));
  const [membership, setMembership] = useState<PreviewMembershipState>("active");
  const [coffeeUsed, setCoffeeUsed] = useState(false);
  const today = useMemo(() => athensToday(), []);
  const area = AREAS.find((a) => a.id === route.area)!;

  useEffect(() => {
    // Light-only brand preview (the ordering app's theme preference is not applied here).
    document.documentElement.classList.remove("dark");
    const prevTitle = document.title;
    document.title = "Evangelou Club — Προεπισκόπηση σχεδίου (DEMO)";
    const robots = document.createElement("meta");
    robots.name = "robots";
    robots.content = "noindex, nofollow";
    document.head.appendChild(robots);
    const onPop = () => setRoute(parsePreviewPath(window.location.pathname));
    window.addEventListener("popstate", onPop);
    return () => {
      document.title = prevTitle;
      robots.remove();
      window.removeEventListener("popstate", onPop);
    };
  }, []);

  const go = (nextArea: Area, screen?: string) => {
    const target = AREAS.find((a) => a.id === nextArea)!;
    const next = { area: nextArea, screen: screen ?? target.screens[0].id };
    window.history.pushState(null, "", previewPath(next.area, next.screen));
    setRoute(next);
    window.scrollTo({ top: 0 });
  };

  return (
    <div className="min-h-screen bg-[#f3eee3]" data-evc-preview={PREVIEW_MARKER}>
      <div className="sticky top-0 z-30">
        <div role="note" className="flex items-center justify-center gap-2 bg-maroon px-4 py-2 text-center text-[12.5px] font-semibold tracking-wide text-white">
          <Icon name="alert" size={15} className="flex-none" />
          <span>DEMO · ΠΡΟΕΠΙΣΚΟΠΗΣΗ ΣΧΕΔΙΑΣΜΟΥ — φανταστικά δεδομένα, καμία πραγματική συνδρομή, πληρωμή ή εξαργύρωση</span>
        </div>
        <div className="border-b border-espresso/12 bg-surface/95 backdrop-blur">
          <div className="mx-auto flex max-w-[1240px] flex-wrap items-center gap-3 px-4 py-3 md:px-6">
            <div className="flex items-center gap-2.5">
              <img src="/logo-evaggelou-color.png" alt="Ευαγγέλου" className="h-7 object-contain" />
              <span className="font-literata text-[15px] font-semibold">Club <span className="font-normal text-espresso/50">· Phase 1</span></span>
            </div>
            <nav className="ml-auto flex gap-1 rounded-xl bg-hairline/70 p-1" aria-label="Περιοχές προεπισκόπησης">
              {AREAS.map((a) => (
                <button key={a.id} type="button" onClick={() => go(a.id)} aria-current={route.area === a.id ? "page" : undefined} className={cx("flex items-center gap-1.5 rounded-lg px-3 py-2 text-[13.5px] font-semibold", route.area === a.id ? "bg-surface text-espresso shadow-sm" : "text-espresso/55 hover:text-espresso")}>
                  <Icon name={a.icon} size={16} />
                  {a.label}
                </button>
              ))}
            </nav>
          </div>
        </div>
      </div>

      <div className="mx-auto grid max-w-[1240px] grid-cols-[minmax(0,1fr)] gap-0 lg:grid-cols-[230px_minmax(0,1fr)]">
        <aside className="min-w-0 border-b border-espresso/10 bg-[#f3eee3] lg:sticky lg:top-[104px] lg:h-[calc(100vh-104px)] lg:overflow-y-auto lg:border-r lg:border-b-0">
          <div className="px-4 py-3 lg:px-5 lg:py-6">
            <p className="mb-2 hidden text-[11px] font-semibold tracking-[0.14em] text-espresso/45 uppercase lg:block">Οθόνες</p>
            <ul className="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:gap-0.5 lg:overflow-visible">
              {area.screens.map((s, i) => (
                <li key={s.id} className="flex-none">
                  <button type="button" onClick={() => go(area.id, s.id)} aria-current={route.screen === s.id ? "page" : undefined} className={cx("w-full rounded-lg px-3 py-2 text-left text-[13.5px] whitespace-nowrap", route.screen === s.id ? "bg-espresso font-semibold text-cream" : "bg-surface text-espresso/70 hover:text-espresso lg:bg-transparent")}>
                    <span className="mr-1.5 text-[11px] opacity-50 tabular-nums">{i + 1}</span>
                    {s.label}
                  </button>
                </li>
              ))}
            </ul>
            {route.area === "customer" && (
              <div className="mt-4 hidden space-y-3 rounded-xl border border-espresso/10 bg-surface p-3.5 lg:block">
                <p className="text-[11px] font-semibold tracking-[0.12em] text-espresso/45 uppercase">Δείγμα κατάστασης</p>
                <div className="space-y-1">
                  {(["active", "pending", "expired"] as const).map((s) => (
                    <label key={s} className="flex cursor-pointer items-center gap-2 text-[13px]">
                      <input type="radio" name="membership" checked={membership === s} onChange={() => setMembership(s)} className="accent-[#5f5335]" />
                      {s === "active" ? "Ενεργή συνδρομή" : s === "pending" ? "Αναμονή πληρωμής" : "Ληγμένη"}
                    </label>
                  ))}
                </div>
                <label className="flex cursor-pointer items-center gap-2 border-t border-espresso/8 pt-2.5 text-[13px]">
                  <input type="checkbox" checked={coffeeUsed} onChange={(e) => setCoffeeUsed(e.target.checked)} className="accent-[#5f5335]" />
                  Ο σημερινός καφές δόθηκε
                </label>
              </div>
            )}
          </div>
          {route.area === "customer" && (
            <div className="flex flex-wrap gap-2 px-4 pb-3 lg:hidden">
              {(["active", "pending", "expired"] as const).map((s) => (
                <button key={s} type="button" onClick={() => setMembership(s)} className={cx("rounded-full border px-3 py-1 text-[12px] font-semibold", membership === s ? "border-bronze-dark bg-bronze-dark text-white" : "border-espresso/15 bg-surface text-espresso/65")}>
                  {s === "active" ? "Ενεργή" : s === "pending" ? "Αναμονή" : "Ληγμένη"}
                </button>
              ))}
              <button type="button" onClick={() => setCoffeeUsed(!coffeeUsed)} className={cx("rounded-full border px-3 py-1 text-[12px] font-semibold", coffeeUsed ? "border-bronze-dark bg-bronze-dark text-white" : "border-espresso/15 bg-surface text-espresso/65")}>
                Καφές δόθηκε
              </button>
            </div>
          )}
        </aside>

        <main className="min-w-0">
          {route.area === "customer" && (
            <CustomerPreview screen={route.screen as CustomerScreen} go={(s) => go("customer", s)} state={membership} coffeeUsedToday={coffeeUsed} today={today} />
          )}
          {route.area === "staff" && <StaffPreview screen={route.screen as StaffScreen} go={(s) => go("staff", s)} today={today} />}
          {route.area === "management" && <ManagementPreview screen={route.screen as ManagementScreen} go={(s) => go("management", s)} today={today} />}
          <footer className="px-6 py-8 text-center text-[12px] text-espresso/45">
            Evangelou Club — προεπισκόπηση σχεδιασμού για έγκριση. Δεν αποτελεί λειτουργική εφαρμογή.
          </footer>
        </main>
      </div>
    </div>
  );
}

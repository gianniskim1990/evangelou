import { useEffect, useMemo, useState } from "react";
import "./preview.css";
import { CustomerPreview } from "./CustomerPreview";
import { ManagementPreview } from "./ManagementPreview";
import { PREVIEW_MARKER, athensToday, type PreviewMembershipState } from "./previewData";
import { AREAS, cx, parsePreviewPath, previewPath, type Area, type CustomerScreen, type ManagementScreen, type StaffScreen } from "./screens";
import { StaffPreview } from "./StaffPreview";

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

  const screens = area.screens;
  const index = screens.findIndex((s) => s.id === route.screen);
  const sample = membership !== "active" ? membership : coffeeUsed ? "used" : "active";
  const setSample = (v: string) => {
    setMembership(v === "used" ? "active" : (v as PreviewMembershipState));
    setCoffeeUsed(v === "used");
  };
  const control = "t-label min-h-10 rounded-full border border-[var(--brass-soft)] bg-[var(--roast-2)] px-3 text-[var(--milk)] hover:border-[var(--brass)]";

  return (
    <div className="evc-ds min-h-screen bg-[#ece7df]" data-evc-preview={PREVIEW_MARKER}>
      {/* Preview tooling, deliberately outside the product's own chrome. */}
      <div className="on-dark sticky top-0 z-40 border-b border-black/30 bg-[var(--roast-2)] text-[var(--milk)]">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2 md:px-5 lg:flex-nowrap">
          <p role="note" className="t-small flex min-w-0 basis-full items-center gap-2.5 lg:basis-auto">
            <span className="flex-none rounded-[4px] bg-[#a3302c] px-2 py-0.5 text-[11.5px] font-bold tracking-[0.04em] text-white">DEMO</span>
            <span className="truncate"><span className="hidden 2xl:inline">ΠΡΟΕΠΙΣΚΟΠΗΣΗ ΣΧΕΔΙΑΣΜΟΥ: </span>φανταστικά δεδομένα, καμία πραγματική συνδρομή, πληρωμή ή εξαργύρωση</span>
          </p>
          <div className="flex min-w-0 flex-1 items-center gap-2 lg:ml-auto lg:flex-none">
            <nav aria-label="Περιοχές προεπισκόπησης" className="hidden rounded-full border border-[var(--brass-soft)] p-0.5 lg:flex">
              {AREAS.map((a) => (
                <button key={a.id} type="button" onClick={() => go(a.id)} aria-current={route.area === a.id ? "page" : undefined} className={cx("t-label min-h-8 rounded-full px-3", route.area === a.id ? "bg-[var(--milk)] text-[var(--roast)]" : "text-[var(--milk)]/70 hover:text-[var(--milk)]")}>
                  {a.label}
                </button>
              ))}
            </nav>
            <label className="sr-only" htmlFor="evc-preview-area">Περιοχή</label>
            <select id="evc-preview-area" value={route.area} onChange={(e) => go(e.target.value as Area)} className={cx(control, "min-w-0 flex-1 lg:hidden")}>
              {AREAS.map((a) => (
                <option key={a.id} value={a.id}>{a.label}</option>
              ))}
            </select>
            <button type="button" aria-label="Προηγούμενη οθόνη" disabled={index <= 0} onClick={() => go(area.id, screens[index - 1].id)} className="hidden h-9 w-7 flex-none text-[18px] text-[var(--milk)]/70 hover:text-[var(--milk)] disabled:opacity-30 md:block">‹</button>
            <label className="sr-only" htmlFor="evc-preview-screen">Οθόνη</label>
            <select id="evc-preview-screen" value={route.screen} onChange={(e) => go(area.id, e.target.value)} className={cx(control, "min-w-0 flex-[1.4] lg:w-[210px] lg:flex-none")}>
              {screens.map((s, i) => (
                <option key={s.id} value={s.id}>{i + 1}. {s.label}</option>
              ))}
            </select>
            <button type="button" aria-label="Επόμενη οθόνη" disabled={index >= screens.length - 1} onClick={() => go(area.id, screens[index + 1].id)} className="hidden h-9 w-7 flex-none text-[18px] text-[var(--milk)]/70 hover:text-[var(--milk)] disabled:opacity-30 md:block">›</button>
            {route.area === "customer" && (
              <>
                <label className="sr-only" htmlFor="evc-preview-sample">Δείγμα κατάστασης μέλους</label>
                <select id="evc-preview-sample" value={sample} onChange={(e) => setSample(e.target.value)} className={cx(control, "min-w-0 flex-1 lg:w-[160px] lg:flex-none")}>
                  <option value="active">Ενεργή</option>
                  <option value="used">Καφές δόθηκε</option>
                  <option value="pending">Αναμονή</option>
                  <option value="expired">Ληγμένη</option>
                </select>
              </>
            )}
          </div>
        </div>
      </div>

      <main className="min-w-0">
        {route.area === "customer" && (
          <CustomerPreview screen={route.screen as CustomerScreen} go={(s) => go("customer", s)} state={membership} coffeeUsedToday={coffeeUsed} today={today} />
        )}
        {route.area === "staff" && <StaffPreview screen={route.screen as StaffScreen} go={(s) => go("staff", s)} today={today} />}
        {route.area === "management" && <ManagementPreview screen={route.screen as ManagementScreen} go={(s) => go("management", s)} today={today} />}
      </main>
    </div>
  );
}

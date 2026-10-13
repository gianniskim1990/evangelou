import type { Dispatch, ReactNode } from "react";
import { COFFEES, coffeeName, greekDate, type PreviewMember } from "./previewData";
import { EXTRAS, extrasFor, quickNotesFor, groupLabel, GROUPS, missing, optionsFor, preparationLine, preparationRows, usualOrder, visibleGroups, isComplete, type CoffeeOrder, type OrderAction } from "./coffeeConfig";
import { cx } from "./screens";
import { DefRows, Glyph } from "./ds";

/*
 * UI-02.R1 design preview: the staff coffee configurator and the order
 * summary. Presentational; state lives in StaffPreview's order reducer.
 * Catalogue and modifiers are illustrative, not approved availability.
 */

function Chip({ selected, onClick, children, role = "radio" }: { selected: boolean; onClick: () => void; children: string; role?: "radio" | "checkbox" }) {
  return (
    <button
      type="button"
      role={role}
      aria-checked={selected}
      onClick={onClick}
      className={cx(
        "press inline-flex min-h-12 items-center gap-2 rounded-full border px-4 text-[16px] font-semibold",
        selected ? "on-dark border-[var(--roast)] bg-[var(--roast)] text-[var(--milk)]" : "border-[var(--rule-strong)] bg-[var(--paper)] text-[var(--ink)] hover:border-[var(--ink)]",
      )}
    >
      {selected && <Glyph name="check" size={16} className="-ml-1 text-[var(--brass)]" />}
      {children}
    </button>
  );
}

function Row({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
  return (
    <div className="grid gap-x-6 gap-y-2.5 border-b border-[var(--rule)] py-4 md:grid-cols-[8.5rem_minmax(0,1fr)] md:items-start">
      <div className="md:pt-3">
        <p className="t-label text-[var(--ink)]">{label}</p>
        {hint && <p className="t-small text-[var(--ink-3)]">{hint}</p>}
      </div>
      {children}
    </div>
  );
}

export function CoffeeConfigurator({ member, order, dispatch, onContinue }: { member: PreviewMember; order: CoffeeOrder; dispatch: Dispatch<OrderAction>; onContinue: () => void }) {
  const usual = usualOrder(member);
  const groups = visibleGroups(order);
  const gaps = missing(order);
  const ready = isComplete(order);
  return (
    <>
      <div className="mx-auto max-w-[1280px] px-5 pt-6 pb-36 md:px-8 md:pt-8">
        <div className="flex flex-wrap items-center justify-between gap-x-8 gap-y-3 rounded-[14px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 py-4">
          <p className="t-body min-w-0 text-[var(--ink)]">
            <span className="text-[var(--ink-2)]">Συνήθως (δείγμα): </span>
            <strong className="font-semibold">{coffeeName(usual.coffee!)}</strong>, {preparationLine(usual)}
          </p>
          <button type="button" onClick={() => dispatch({ type: "prefill", order: usual })} className="press min-h-12 flex-none rounded-full border border-[var(--roast)] px-5 text-[16px] font-semibold text-[var(--ink)] hover:bg-[var(--roast)] hover:text-[var(--milk)]">
            Όπως συνήθως
          </button>
        </div>
        <p className="t-small mt-2 text-[var(--ink-3)]">Ενδεικτικό δείγμα. Η πραγματική πρόταση θα προκύπτει από το ιστορικό εξαργυρώσεων και δεν είναι επιβεβαιωμένη παραγγελία· ελέγξτε την και αλλάξτε ό,τι χρειάζεται.</p>

        <div className="mt-8 grid grid-cols-[minmax(0,1fr)] gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
          <section aria-labelledby="evc-order-coffee">
            <h2 id="evc-order-coffee" className="t-title text-[var(--ink)]">Καφές</h2>
            <p className="t-small mt-1 text-[var(--ink-3)]">Ενδεικτικός κατάλογος, δεν έχει εγκριθεί ακόμη.</p>
            <div role="radiogroup" aria-labelledby="evc-order-coffee" className="mt-4 grid grid-cols-2 gap-2.5 md:grid-cols-3 lg:grid-cols-2">
              {COFFEES.map((c) => {
                const on = order.coffee === c.code;
                return (
                  <button key={c.code} type="button" role="radio" aria-checked={on} onClick={() => dispatch({ type: "coffee", coffee: c.code })} className={cx("press flex min-h-20 flex-col justify-between rounded-[14px] border px-4 py-3 text-left", on ? "on-dark border-[var(--roast)] bg-[var(--roast)] text-[var(--milk)]" : "border-[var(--rule-strong)] bg-[var(--paper)] text-[var(--ink)] hover:border-[var(--ink)]")}>
                    <span className="flex items-start justify-between gap-2">
                      <span className="text-[17px] leading-snug font-semibold">{c.label}</span>
                      {on && <Glyph name="check" size={20} className="flex-none text-[var(--brass)]" />}
                    </span>
                    <span className={cx("t-small", on ? "text-[var(--milk)]/65" : "text-[var(--ink-2)]")}>{c.note}</span>
                  </button>
                );
              })}
            </div>
          </section>

          <section aria-labelledby="evc-order-prep">
            <h2 id="evc-order-prep" className="t-title border-b border-[var(--rule-strong)] pb-3 text-[var(--ink)]">Παρασκευή</h2>
            {!order.coffee && <p className="t-body py-8 text-[var(--ink-2)]">Διαλέξτε καφέ για να εμφανιστούν οι επιλογές που του ταιριάζουν.</p>}
            {groups.map((g) => {
              const group = GROUPS[g];
              const value = order.choices[g];
              return (
                <Row key={g} label={groupLabel(order.coffee, g)} hint={group.required ? undefined : "Προαιρετικό. Χωρίς επιλογή: κανονικά."}>
                  <div role="radiogroup" aria-label={groupLabel(order.coffee, g)} aria-required={group.required} className="flex flex-wrap gap-2">
                    {optionsFor(order.coffee!, g).map((o) => (
                      <Chip key={o.id} selected={value === o.id} onClick={() => dispatch({ type: "choice", group: g, value: value === o.id && !group.required ? null : o.id })}>
                        {o.label}
                      </Chip>
                    ))}
                  </div>
                </Row>
              );
            })}
            {extrasFor(order.coffee).length > 0 && (
              <Row label="Επιπλέον">
                <div className="flex flex-wrap gap-2">
                  {extrasFor(order.coffee).map((e) => (
                    <Chip key={e} role="checkbox" selected={order.extras.includes(e)} onClick={() => dispatch({ type: "extra", extra: e })}>
                      {EXTRAS[e].label}
                    </Chip>
                  ))}
                </div>
              </Row>
            )}
            {order.coffee && (
              <Row label="Οδηγίες" hint="Προαιρετικό">
                <div>
                  <div className="flex flex-wrap gap-2" aria-label="Γρήγορες οδηγίες για αυτόν τον καφέ">
                    {quickNotesFor(order.coffee).map((q) => (
                      <Chip key={q.id} role="checkbox" selected={order.quickNotes.includes(q.id)} onClick={() => dispatch({ type: "quick-note", id: q.id })}>
                        {q.label}
                      </Chip>
                    ))}
                  </div>
                </div>
              </Row>
            )}
          </section>
        </div>
      </div>

      <div className="sticky bottom-0 z-10 border-t border-[var(--rule)] bg-[var(--paper)]">
        <div className="mx-auto flex max-w-[1280px] items-center justify-between gap-4 px-5 py-3 md:px-8">
          <p className="t-body min-w-0 flex-1 overflow-hidden text-[var(--ink)]" aria-live="polite">
            {ready ? (
              <>
                <strong className="block truncate font-semibold">{coffeeName(order.coffee!)}</strong>
                <span className="t-small block truncate text-[var(--ink-2)]">{preparationLine(order)}</span>
              </>
            ) : (
              <span className="text-[var(--ink-2)]">{`Λείπει: ${gaps.join(", ")}`}</span>
            )}
          </p>
          <button type="button" disabled={!ready} onClick={onContinue} className="press min-h-14 flex-none rounded-[12px] bg-[var(--roast)] px-8 text-[17px] font-semibold text-[var(--milk)] hover:bg-[var(--roast-3)] disabled:cursor-not-allowed disabled:opacity-40">
            Συνέχεια
          </button>
        </div>
      </div>
    </>
  );
}

/** The full preparation as it would be confirmed, plus member and Athens date. */
export function OrderSummary({ member, order, today }: { member: PreviewMember; order: CoffeeOrder; today: string }) {
  const rows = preparationRows(order);
  return (
    <DefRows
      rows={[
        ["Μέλος", member.name],
        ...rows.map(([k, v]): [string, ReactNode] => [k, k === "Καφές" ? <span key="c" className="t-title">{v}</span> : v]),
        ["Ημέρα", `${greekDate(today, true)}, ο 1 καφές της ημέρας (ώρα Ελλάδας)`],
      ]}
    />
  );
}

import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import type { Action } from "../redemptionRecovery/machine";
import { announcementFor, focusFor, screenContract, TOUCH_TARGET, type Announcement, type RecoveryScreenContract } from "../redemptionRecovery/a11yContract";
import type { RecoveryView } from "../redemptionRecovery/viewModel";
import "./recoveryScreen.css";

/**
 * UI-04N — standalone Staff Recovery Screen. Renders ONE RecoveryView through
 * its accessibility contract (screenContract / announcementFor / focusFor).
 *
 * It is presentation only and NOT wired into the real StaffApp:
 *  - controls are exactly contract.actions, in that order and with those
 *    labels; there is no other button, link or shortcut;
 *  - a click calls the injected onAction(name) and nothing else: no request,
 *    no retry, no timer, no storage;
 *  - focus is only ever moved to the heading or the busy status (never to a
 *    button) and never on a re-render of the same case;
 *  - announcements go through two persistent live regions: polite status and
 *    assertive alert (authorization / integrity blocks only).
 *
 * Styling is plain CSS scoped to the "evc-rs" prefix so it adds nothing to
 * the shared Tailwind stylesheet of the shipped bundles.
 */

export interface RecoveryScreenProps {
  view: RecoveryView;
  /** Called with the action NAME only. The host decides what (if anything) happens. */
  onAction: (action: Action) => void;
}

const ICON_PATHS: Record<RecoveryScreenContract["icon"], ReactNode> = {
  info: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v5M12 8h.01" />
    </>
  ),
  check: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="m8 12.5 2.8 2.8L16.5 9.5" />
    </>
  ),
  attention: (
    <>
      <path d="M12 4 3 19.5h18L12 4Z" />
      <path d="M12 10v4.5M12 17h.01" />
    </>
  ),
  warning: (
    <>
      <rect x="4" y="4" width="16" height="16" rx="3" transform="rotate(45 12 12)" />
      <path d="M12 8.5v4.5M12 15.8h.01" />
    </>
  ),
  stop: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="m8.5 8.5 7 7M15.5 8.5l-7 7" />
    </>
  ),
};

function ToneIcon({ icon }: { icon: RecoveryScreenContract["icon"] }) {
  return (
    <svg className="evc-rs__icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      {ICON_PATHS[icon]}
    </svg>
  );
}

const ACTIONS_LABEL = "Ενέργειες";

export function RecoveryScreen({ view, onAction }: RecoveryScreenProps) {
  const contract = screenContract(view);
  const headingId = useId();
  const escalationId = useId();
  const headingRef = useRef<HTMLHeadingElement>(null);
  const busyRef = useRef<HTMLDivElement>(null);
  const previous = useRef<RecoveryView | null>(null);
  const focusedAction = useRef<Action | null>(null);
  const [polite, setPolite] = useState("");
  const [assertive, setAssertive] = useState("");

  // Runs after every commit. A re-render of the same case changes nothing: no
  // announcement, and focus is kept (or falls back to the heading if the focused
  // control is no longer offered).
  useEffect(() => {
    const prev = previous.current;
    const announcement: Announcement | null = announcementFor(prev, view);
    if (announcement !== null) {
      if (announcement.priority === "assertive") {
        setAssertive(announcement.text);
        setPolite("");
      } else {
        setPolite(announcement.text);
        setAssertive("");
      }
    }
    const intent = focusFor(prev, view, focusedAction.current);
    if (intent.kind === "heading") headingRef.current?.focus();
    else if (intent.kind === "status") busyRef.current?.focus();
    if (intent.kind !== "keep") focusedAction.current = null;
    previous.current = view;
  }, [view]);

  const tone = `evc-rs--${view.tone}`;
  const escalation = contract.escalation;

  return (
    <div className="evc-rs" data-case={contract.caseKey}>
      <section
        className={`evc-rs__card ${tone}${contract.showPrepare ? " evc-rs__card--prepare" : ""}`}
        aria-labelledby={headingId}
        aria-busy={contract.busy ? "true" : "false"}
        style={{ ["--evc-rs-target" as string]: `${TOUCH_TARGET.preferredCssPx}px`, ["--evc-rs-gap" as string]: `${TOUCH_TARGET.minimumGapCssPx}px` }}
      >
        <header className="evc-rs__head">
          <ToneIcon icon={contract.icon} />
          <h2 id={headingId} ref={headingRef} tabIndex={-1} className="evc-rs__title">
            {contract.heading}
          </h2>
        </header>

        {contract.uncertainMarker !== null && (
          <p className="evc-rs__marker">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true" focusable="false">
              <path d="M9.5 9a2.5 2.5 0 1 1 3.6 2.2c-.8.4-1.1 1-1.1 1.8M12 17h.01" />
            </svg>
            <span>{contract.uncertainMarker}</span>
          </p>
        )}

        {contract.busy ? (
          <div className="evc-rs__busy" ref={busyRef} tabIndex={-1}>
            <span className="evc-rs__spinner" aria-hidden="true" />
            <div className="evc-rs__body">
              {contract.body.map((line) => (
                <p key={line}>{line}</p>
              ))}
            </div>
          </div>
        ) : (
          <div className={`evc-rs__body${contract.showPrepare ? " evc-rs__body--prepare" : ""}`}>
            {contract.body.map((line) => (
              <p key={line}>{line}</p>
            ))}
          </div>
        )}

        {contract.actions.length > 0 && (
          <div className="evc-rs__actions" role="group" aria-label={ACTIONS_LABEL}>
            {contract.actions.map((a) => (
              <button
                key={a.action}
                type="button"
                data-action={a.action}
                className={`evc-rs__btn evc-rs__btn--${a.variant}`}
                onFocus={() => {
                  focusedAction.current = a.action;
                }}
                onClick={() => onAction(a.action)}
              >
                {a.label}
              </button>
            ))}
          </div>
        )}

        {escalation.presentation !== "hidden" && (
          <aside className={`evc-rs__escalation evc-rs__escalation--${escalation.presentation === "panel_primary" ? "primary" : "secondary"}`} aria-labelledby={escalationId}>
            <h3 id={escalationId} className="evc-rs__escalation-title">
              {escalation.heading}
            </h3>
            <ul>
              {escalation.steps.map((step) => (
                <li key={step}>{step}</li>
              ))}
            </ul>
          </aside>
        )}
      </section>

      <div className="evc-rs__sr" role="status" aria-live="polite" aria-atomic="true" data-live="polite">
        {polite}
      </div>
      <div className="evc-rs__sr" role="alert" aria-live="assertive" aria-atomic="true" data-live="assertive">
        {assertive}
      </div>
    </div>
  );
}

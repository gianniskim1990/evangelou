import { useSyncExternalStore } from "react";
import { Btn } from "../preview/ds";
import { PUSH_COPY, type MemberPushFlow } from "./pwa/push";

/**
 * STAGE-08 round 12 (owner decision D26 part 2): «Ειδοποιήσεις» below the member area (never over the QR). The button
 * «Θέλω ειδοποιήσεις» is the member's explicit consent: only its tap asks the browser for permission (pwa/push.ts).
 * iPhone in the browser: how to install first; plain permalinks: why there are none yet; otherwise nothing.
 */
export function PushControl({ flow }: { flow: MemberPushFlow }) {
  const { phase } = useSyncExternalStore(flow.subscribe, flow.getState);
  if (phase === "hidden") return null;
  const text =
    phase === "plain"
      ? PUSH_COPY.plain
      : phase === "ios_install"
        ? PUSH_COPY.ios
        : phase === "denied"
          ? PUSH_COPY.denied
          : phase === "on"
            ? PUSH_COPY.on
            : phase === "asking"
              ? PUSH_COPY.asking
              : phase === "stopping"
                ? PUSH_COPY.stopping
                : PUSH_COPY.explain;
  return (
    <aside aria-labelledby="evc-push-title" data-evc-push={phase} className="bg-[var(--stone)] px-5 pt-2 pb-10 md:px-10">
      <div className="mx-auto max-w-[1080px] rounded-[18px] border border-[var(--rule-strong)] bg-[var(--paper)] px-5 py-5 md:px-7">
        <h2 id="evc-push-title" className="t-title text-[var(--roast)]">
          {PUSH_COPY.title}
        </h2>
        <p className="t-body mt-2 max-w-[52ch] text-[var(--ink-2)]" aria-live="polite">
          {text}
        </p>
        {phase === "error" && (
          <p role="alert" className="t-body mt-2 max-w-[52ch] text-[var(--ink-2)]">
            {PUSH_COPY.error}
          </p>
        )}
        {(phase === "idle" || phase === "error") && (
          <div className="mt-5 flex flex-wrap gap-3">
            <Btn tone="roast" onClick={() => void flow.enable()} data-evc-action="push-enable">
              {PUSH_COPY.enable}
            </Btn>
          </div>
        )}
        {phase === "on" && (
          <div className="mt-5 flex flex-wrap gap-3">
            <Btn tone="line" onClick={() => void flow.disable()} data-evc-action="push-disable">
              {PUSH_COPY.disable}
            </Btn>
          </div>
        )}
      </div>
    </aside>
  );
}
